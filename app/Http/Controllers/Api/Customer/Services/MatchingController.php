<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\MatchingCheckoutRequest;
use App\Jobs\Services\MbwayPaymentCheckJob;
use App\Http\Requests\Customer\StartMatchingRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\Voucher;
use App\Services\Common\Services\MaterializePendingSchedule;
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
     * Abre o pedido e convida a primeira onda.
     *
     * Igual nos dois modos: notifica os melhores, e as aceitações vão chegando
     * ao ecrã do cliente à medida que aparecem. O que muda entre imediato e
     * agendado é só a janela de resposta de cada convite.
     */
    public function start(StartMatchingRequest $request): ApiSuccessResponse|ApiErrorResponse
    {
        DB::beginTransaction();

        try {
            $customer = $this->fetchCustomer();
            $address = $this->fetchCustomerMainAddress($customer);
            $serviceType = ServicesType::findOrFail($request->integer('service_type'));
            $isScheduled = $request->boolean('scheduled');

            // Um pedido em seleção de cada vez. Um duplo-toque ou um retry de
            // rede da app abria dois pedidos e convidava duas ondas de
            // profissionais para o mesmo trabalho — e o cliente ficava com dois
            // ecrãs de espera. Devolve-se o que já está aberto em vez de um erro:
            // do ponto de vista dele o toque funcionou.
            $existing = Service::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', [ServiceStatus::MATCHING, ServiceStatus::AWAITING_PAYMENT])
                ->latest('id')
                ->first();

            if ($existing) {
                DB::commit();

                return new ApiSuccessResponse($this->payload($existing));
            }

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
            // Fora do $fillable por ser dinheiro: atribui-se antes de gravar,
            // porque a coluna não tem valor por omissão.
            $service->payment_status = PaymentStatus::PENDING;

            // A agenda não pode nascer já: `schedule.vendor_id` é NOT NULL e
            // ainda não há profissional. A intenção fica em
            // `pending_schedule_data` — o mesmo sítio onde o fluxo antigo a
            // guarda enquanto espera pelo 3DS/MBWay — e materializa-se depois
            // do pagamento, quando já se sabe quem é.
            if ($isScheduled) {
                $service->pending_schedule_data = [
                    'scheduled' => true,
                    'schedule' => $request->input('schedule', []),
                ];
            }

            $service->save();

            $candidates = $this->matching->dispatchNextWave($service);

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
     * O candidato já aceitou — nos dois modos — por isso o serviço passa direto
     * a AwaitingPayment e segue para o checkout. O `awaiting_vendor` continua na
     * resposta, sempre falso, porque a app usa-o para decidir para onde vai a
     * seguir; deixá-lo cair partia o ecrã sem aviso.
     */
    public function select(Service $service, ServiceCandidate $candidate): ApiSuccessResponse|ApiErrorResponse
    {
        if (! $this->owns($service) || $candidate->service_id !== $service->id) {
            return new ApiErrorResponse(new Exception, 'Candidate not found', 404);
        }

        if ($service->status !== ServiceStatus::MATCHING) {
            return new ApiErrorResponse(new Exception, 'This request is no longer awaiting a choice', 409);
        }

        if (! $this->matching->select($candidate)) {
            return new ApiErrorResponse(new Exception, 'This professional is no longer available', 409);
        }

        return new ApiSuccessResponse($this->payload($service->refresh()) + [
            'awaiting_vendor' => false,
        ]);
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

            // MBWay fica pendente do push no banco do cliente. Sem este job
            // ninguém volta a perguntar ao Payshop se foi confirmado, e o
            // serviço ficava pago do lado do cliente e por confirmar do nosso —
            // com o profissional escolhido a nunca saber que ganhou.
            // Despachado DEPOIS do commit, para o job não correr contra uma
            // transação que ainda pode reverter.
            if ($method === 'mbway' && $service->payment_status !== PaymentStatus::PAID) {
                MbwayPaymentCheckJob::dispatch($service)->delay(
                    now()->addSeconds(config('services.request.mbway_payment_check_timeout'))
                );
            }

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
     * `Accepted` e não `Pending`: a aceitação já aconteceu ANTES do pagamento,
     * que é precisamente a inversão que este fluxo veio trazer.
     *
     * A agenda materializa-se aqui, pelo caminho que já existia
     * (MaterializePendingSchedule): é agora que há profissional, e é esse
     * serviço que cria a linha, notifica e arma o job de cancelamento. Duplicar
     * essa lógica seria criar um segundo sítio para ela divergir.
     */
    private function activate(Service $service): void
    {
        if ($service->pending_schedule_data) {
            // Mesma sequência do fluxo antigo: a materialização exige PENDING
            // e é ela que passa a SCHEDULED. Pôr SCHEDULED aqui fazia o
            // `acceptSchedule` rejeitar — com o dinheiro já cobrado.
            $service->status = ServiceStatus::PENDING;
            $service->save();

            // alreadyAccepted: neste fluxo o profissional aceitou ANTES do
            // pagamento. Sem isto, a materialização voltava a perguntar-lhe e
            // armava o cancelamento por falta de resposta — que acabava por
            // cancelar e reembolsar um serviço pago e aceite.
            app(MaterializePendingSchedule::class)->handle($service->refresh(), alreadyAccepted: true);

            return;
        }

        // Imediato: o profissional já aceitou antes do pagamento, que é a
        // inversão que este fluxo veio trazer. Não se passa pela
        // materialização, que notificaria como se fosse um serviço novo.
        $service->status = ServiceStatus::ACCEPTED;
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
