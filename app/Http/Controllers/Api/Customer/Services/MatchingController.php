<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\MatchingCheckoutRequest;
use App\Http\Requests\Customer\StartMatchingRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\Voucher;
use App\Services\Matching\MatchingService;
use App\Settings\MatchingSettings;
use App\Trait\Services\CalculateServicePriceForCustomer;
use App\Trait\Services\ProcessesServicePayment;
use App\Exceptions\MatchingCheckoutException;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Seleção de profissional pelo cliente — ver docs/matching.md.
 *
 * A ordem é a inversa do fluxo antigo: primeiro há candidatos, depois o cliente
 * escolhe, e só no fim se cobra. No fluxo antigo cobrava-se primeiro e podia
 * não haver ninguém, o que obrigava a desfazer o pagamento.
 */
class MatchingController extends Controller
{
    use CalculateServicePriceForCustomer;
    use ProcessesServicePayment;

    public function __construct(
        private MatchingService $matching,
        private MatchingSettings $settings,
    ) {
    }

    /**
     * Abre o pedido e põe candidatos em cima da mesa.
     *
     * Imediato: shortlist montada sem notificar ninguém — o cliente vê opções
     * sem esperar, e ninguém aceita em vão.
     * Agendado: primeira onda notificada; as aceitações vão chegando.
     */
    public function start(StartMatchingRequest $request): ApiSuccessResponse|ApiErrorResponse
    {
        DB::beginTransaction();

        try {
            $customer = $this->fetchCustomer();
            $address = $this->fetchCustomerMainAddress($customer);
            $serviceType = ServicesType::findOrFail($request->integer('service_type'));
            $isScheduled = $request->boolean('scheduled');

            $service = new Service([
                'customer_id' => $customer->id,
                'vendor_id' => null,
                'services_type_id' => $serviceType->id,
                'quantity' => max(1, $request->integer('quantity', 1)),
                'status' => ServiceStatus::MATCHING,
                'is_test' => (bool) ($customer->is_test ?? false),
                'customer_notes' => $request->get('customer_notes'),
                'address' => [
                    'name' => $address->name,
                    'street_name' => $address->street_name,
                    'street_number' => $address->street_number,
                    'additional_info' => $address->additional_info,
                    'postal_code' => $address->postal_code,
                    'city' => $address->city,
                    'state' => $address->state,
                    'country' => $address->country,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                ],
            ]);

            // Fora do $fillable por ser dinheiro: atribui-se antes de gravar,
            // porque a coluna não tem valor por omissão.
            $service->payment_status = PaymentStatus::PENDING;
            $service->save();

            $candidates = $isScheduled
                ? $this->matching->dispatchNextWave($service)
                : $this->matching->buildShortlist($service);

            // Ninguém elegível: falha já, para o cliente tentar outra vez em vez
            // de ficar num ecrã de espera que nunca resolve.
            if ($candidates->isEmpty()) {
                $this->matching->fail($service);
                DB::commit();

                return new ApiSuccessResponse($this->payload($service->refresh()));
            }

            DB::commit();

            return new ApiSuccessResponse($this->payload($service->refresh()));
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('[matching] falha ao abrir pedido', [
                'customer_id' => auth('api')->id(),
                'error' => $e->getMessage(),
            ]);

            return new ApiErrorResponse($e);
        }
    }

    /** Estado do pedido e quem há para escolher neste momento. */
    public function show(Service $service): ApiSuccessResponse|ApiErrorResponse
    {
        if (! $this->owns($service)) {
            return new ApiErrorResponse(new Exception, 'Service not found', 404);
        }

        return new ApiSuccessResponse($this->payload($service));
    }

    /**
     * O cliente escolheu.
     *
     * No agendado o candidato já aceitou, e o serviço passa a AwaitingPayment.
     * No imediato ainda ninguém foi notificado: o escolhido é chamado agora, e
     * só depois de ele aceitar é que há checkout.
     */
    public function select(Service $service, ServiceCandidate $candidate): ApiSuccessResponse|ApiErrorResponse
    {
        if (! $this->owns($service) || $candidate->service_id !== $service->id) {
            return new ApiErrorResponse(new Exception, 'Candidate not found', 404);
        }

        if ($service->status !== ServiceStatus::MATCHING) {
            return new ApiErrorResponse(new Exception, 'This request is no longer awaiting a choice', 409);
        }

        if ($candidate->status === CandidateStatus::SHORTLISTED) {
            // Fluxo imediato: é agora que a pessoa é chamada — e é a única
            // chamada, porque o cliente já decidiu.
            $candidate->update([
                'status' => CandidateStatus::NOTIFIED,
                'notified_at' => now(),
                'expires_at' => now()->addSeconds($this->settings->vendor_response_seconds_immediate),
            ]);

            return new ApiSuccessResponse($this->payload($service->refresh()) + [
                'awaiting_vendor' => true,
            ]);
        }

        if (! $this->matching->select($candidate)) {
            return new ApiErrorResponse(new Exception, 'This professional is no longer available', 409);
        }

        return new ApiSuccessResponse($this->payload($service->refresh()));
    }

    /**
     * Cobra o serviço já atribuído.
     *
     * Parte do preço CONGELADO no candidato e não recalcula: a comissão horária
     * muda com a hora do dia, por isso recalcular aqui daria um número diferente
     * do que o cliente viu ao escolher. Cupão e saldo aplicam-se por cima, com
     * as mesmas regras do fluxo antigo (buildTransactionTotals).
     */
    public function checkout(MatchingCheckoutRequest $request, Service $service): ApiSuccessResponse|ApiErrorResponse
    {
        DB::beginTransaction();

        try {
            if (! $this->owns($service)) {
                throw new MatchingCheckoutException('Service not found', 404);
            }

            if ($service->status !== ServiceStatus::AWAITING_PAYMENT) {
                throw new MatchingCheckoutException('This service is not awaiting payment', 409);
            }

            $customer = $this->fetchCustomer();
            $selected = $service->candidates()->where('status', CandidateStatus::SELECTED)->firstOrFail();
            $method = $request->get('method', 'credit_card');

            $voucher = $request->get('voucher_id') ? Voucher::find($request->integer('voucher_id')) : null;

            if ($voucher) {
                // Mesmo lock do fluxo antigo: serializa reservas concorrentes
                // com o mesmo cupão para fechar a corrida ao limite de usos.
                $voucher = Voucher::whereKey($voucher->getKey())->lockForUpdate()->first();

                if (! $voucher || ! $voucher->isValid() || ! $voucher->canBeUsedBy($customer)) {
                    throw new MatchingCheckoutException('This voucher is not active or was already used', 422);
                }
            }

            $total = $this->buildTransactionTotals(
                $customer,
                (int) $selected->quoted_amount,
                (int) $selected->quoted_amount_for_vendor,
                (float) $selected->quoted_distance,
                $voucher,
            );

            $service->amount = $total['amount'];
            $service->amount_for_vendor = $total['amount_for_vendor'];
            $service->original_amount = $total['original_amount'];
            $service->discount_amount = $total['discount_amount'];
            $service->credit_used = $total['balance_total_used'];
            $service->voucher_id = $voucher?->id;
            $service->payment_status = PaymentStatus::PENDING;

            // Mesma barreira final do fluxo antigo: a plataforma nunca pode
            // cobrar ao cliente menos do que paga ao profissional. Dentro da
            // transação — um throw faz rollback e nada é cobrado.
            if ($service->amount < $service->amount_for_vendor) {
                \Log::error('[matching] invariante de preço violada (comissão negativa)', [
                    'service_id' => $service->id,
                    'amount' => $service->amount,
                    'amount_for_vendor' => $service->amount_for_vendor,
                    'voucher_id' => $voucher?->id,
                ]);

                throw new Exception('Invalid service pricing: commission would be negative');
            }

            $service->save();

            // Conta de teste: não há cobrança remota nenhuma, tal como no fluxo
            // antigo (handleServiceCreationWithObjects sai mais cedo). O método
            // de pagamento só se resolve quando há mesmo o que cobrar — exigi-lo
            // antes fazia falhar contas de teste que nunca terão cartão.
            if ($service->is_test) {
                $validationUrl = null;
                $service->payment_status = PaymentStatus::PAID;
                $service->save();
            } else {
                $paymentMethod = $this->resolvePaymentMethod($request, $customer, $method);

                $validationUrl = $method === 'mbway'
                    ? $this->processMbwayPayment($customer, $service, $service->vendor, $total, $paymentMethod)
                    : $this->processCreditCardPayment($customer, $service, $service->vendor, $total, $paymentMethod);
            }

            if ($service->payment_status === PaymentStatus::PAID) {
                $this->activate($service);
            }

            DB::commit();

            return new ApiSuccessResponse($this->payload($service->refresh()) + [
                'payment_validationUrl' => $validationUrl,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('[matching] falha no checkout', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return new ApiErrorResponse($e);
        }
    }

    /**
     * Pago: o serviço deixa de estar em seleção e entra no fluxo normal.
     *
     * `Accepted` e não `Pending`: a aceitação já aconteceu antes do pagamento,
     * que é precisamente a inversão que este fluxo veio trazer.
     */
    private function activate(Service $service): void
    {
        $service->status = $service->schedule
            ? ServiceStatus::SCHEDULED
            : ServiceStatus::ACCEPTED;

        $service->save();
    }

    private function resolvePaymentMethod(MatchingCheckoutRequest $request, $customer, string $method)
    {
        if ($method === 'mbway') {
            return $customer->addMbWay($request->get('mbway_phone'));
        }

        $paymentMethodId = $request->get('payment_method', $customer->default_payment_method_id);

        $paymentMethod = $paymentMethodId
            ? $customer->paymentMethods()->find($paymentMethodId)
            : $customer->paymentMethods()->where('type', '!=', 'mbway')->latest()->first();

        if (! $paymentMethod) {
            throw new MatchingCheckoutException('Invalid payment method', 422);
        }

        return $paymentMethod;
    }

    private function owns(Service $service): bool
    {
        return $service->customer_id === auth('api')->id();
    }

    private function payload(Service $service): array
    {
        $candidates = $this->matching->selectableFor($service)->load('vendor.user');

        return [
            'service' => [
                'id' => $service->id,
                'status' => $service->status,
                'payment_status' => $service->payment_status,
                'amount' => $service->amount,
                'vendor_id' => $service->vendor_id,
            ],
            'candidates' => $candidates->map(fn (ServiceCandidate $c) => [
                'id' => $c->id,
                'rank' => $c->rank,
                'vendor' => [
                    'id' => $c->vendor_id,
                    'name' => $c->vendor?->user?->name,
                    'avatar' => $c->vendor?->user?->avatar,
                ],
                // null = ainda sem avaliações. Não se inventa nota.
                'rating' => $c->rating_average === null ? null : round($c->rating_average / 100, 2),
                'rating_count' => $c->rating_count,
                'amount' => $c->quoted_amount,
                'distance' => (float) $c->quoted_distance,
                'is_new_vendor' => $c->is_new_vendor_slot,
            ])->values(),
            // Menos do que a shortlist é normal: se só dois puderem, mostram-se dois.
            'expected_candidates' => $this->settings->shortlist_size,
        ];
    }
}
