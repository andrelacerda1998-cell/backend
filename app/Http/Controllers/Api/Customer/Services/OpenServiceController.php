<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Customer\Schedule\AcceptScheduleEvent;
use App\Events\Vendor\Schedule\CreateScheduleEvent;
use App\Events\Vendor\Schedule\ServiceScheduledEvent;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Exceptions\Api\Customer\PaymentMethodDisabled;
use App\Exceptions\Api\Customer\PaymentNotComplete;
use App\Exceptions\Api\Customer\PaymentRefused;
use App\Exceptions\Api\Customer\VoucherNotUsable;
use App\Http\Controllers\Api\Customer\Services\traits\NotifyVendor;
use App\Http\Controllers\Api\Customer\Services\traits\ProcessPendingScheduleAfterPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\Services\OpenServiceRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Jobs\Services\MbwayPaymentCheckJob;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Services\Common\Services\AcceptService;
use App\Services\Common\Services\MaterializePendingSchedule;
use App\Services\Common\Services\MbwayPaymentResolver;
use App\Trait\GeoAddress;
use App\Trait\Services\CalculateServicePriceForCustomer;
use Exception;
use Illuminate\Support\Carbon;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Exceptions\Api\CreditCardValidationRequired;
use RwInteractive\PayshopSdk\Models\PaymentMethod;

class OpenServiceController extends Controller
{
    use \App\Trait\Services\ProcessesServicePayment;
    use CalculateServicePriceForCustomer, GeoAddress, NotifyVendor, ProcessPendingScheduleAfterPayment;

    public function __construct(
        private readonly AcceptService $acceptService,
    ) {}

    public function creditCard(OpenServiceRequest $request)
    {
        \DB::beginTransaction();
        try {
            if (! config('payment_methods.credit_card.enabled')) {
                throw new PaymentMethodDisabled;
            }

            $user = auth('api')->user();
            $paymentMethodId = $request->get('payment_method', $user->default_payment_method_id);
            $paymentMethod = $paymentMethodId
                ? $user->paymentMethods()->find($paymentMethodId)
                : $user->paymentMethods()->where('type', '!=', 'mbway')->latest()->first();
            if (! $paymentMethod) {
                throw new Exception('Invalid payment method');
            }

            if ($this->cardIsExpired($paymentMethod->expire_month, $paymentMethod->expire_year)) {
                throw new Exception('Credit card expired');
            }

            [$customer, $vendor, $serviceType, $service, $voucher] = $this->buildService($request);

            $response = $this->handleServiceCreationWithObjects(
                $vendor,
                $serviceType,
                $service,
                $paymentMethod,
                'credit_card',
                $request->boolean('scheduled'),
                $voucher
            );

            $service = $response['service'];
            $validationUrl = $response['validationUrl'];
            $this->attachCustomerPhotos($request, $service);

            if ($validationUrl) {
                $this->storePendingScheduleData($request, $service);
            } else {
                $this->createScheduleIfRequested($request, $vendor, $service);
                $service->refresh();
                $this->notifyVendor($service, $vendor);
                $this->dispatchCancelJob($service);
            }

            \DB::commit();

            return new ApiSuccessResponse($this->formatServiceResponse($service, $validationUrl));
        } catch (Exception $exception) {
            \DB::rollBack();

            return new ApiErrorResponse($exception);
        }
    }

    public function mbway(OpenServiceRequest $request)
    {
        \DB::beginTransaction();
        try {
            if (! config('payment_methods.mbway.enabled')) {
                throw new PaymentMethodDisabled;
            }

            $mbwayPhone = $request->get('mbway_phone');
            if (! $mbwayPhone) {
                throw new Exception('MBWay phone number is required');
            }

            [$customer, $vendor, $serviceType, $service, $voucher] = $this->buildService($request);

            $paymentMethod = $customer->addMbWay($mbwayPhone);

            $response = $this->handleServiceCreationWithObjects(
                $vendor,
                $serviceType,
                $service,
                $paymentMethod,
                'mbway',
                $request->boolean('scheduled'),
                $voucher
            );

            $service = $response['service'];
            $validationUrl = $response['validationUrl'];
            $this->attachCustomerPhotos($request, $service);

            if ($service->payment_status === PaymentStatus::PAID) {
                // Fully covered by wallet credit or a test account — no MBWay push is pending,
                // so make the service visible to the vendor immediately (mirrors creditCard()).
                $this->createScheduleIfRequested($request, $vendor, $service);
                $service->refresh();
                $this->notifyVendor($service, $vendor);
                $this->dispatchCancelJob($service);
            } else {
                // MBWay push pending — defer schedule creation / vendor visibility until the
                // payment is confirmed. MbwayPaymentCheckJob materializes it on confirmation,
                // or cancels the service (RefusedMbway/ExpiredMbway) if it never confirms.
                $this->storePendingScheduleData($request, $service);
            }

            \DB::commit();
            $this->dispatchMbwayPaymentCheckJob($service);

            return new ApiSuccessResponse($this->formatServiceResponse($service, $validationUrl));
        } catch (Exception $exception) {
            \DB::rollBack();

            return new ApiErrorResponse($exception);
        }
    }

    private function parseHolderName(string $holderName): array
    {
        $parts = preg_split('/\s+/', trim($holderName), 2);
        $firstName = ucfirst(strtolower($parts[0] ?? 'Guest'));
        $lastName = isset($parts[1]) ? ucwords(strtolower($parts[1])) : '';

        return [$firstName, $lastName];
    }

    private function buildService(OpenServiceRequest $request): array
    {
        $customer = $this->fetchCustomer();
        $serviceType = ServicesType::find($request->get('service_type'));
        $isScheduled = $request->boolean('scheduled');

        $vendor = $this->findVendor($request->get('vendor_id'), $isScheduled);

        // Multi-morada: quando o pedido traz address_id, usa essa morada (se for
        // do cliente); senão, a principal. O snapshot fica com a morada certa.
        $address = $request->filled('address_id')
            ? $this->fetchCustomerAddressById($customer, (int) $request->get('address_id'))
            : $this->fetchCustomerMainAddress($customer);

        $service = $this->createService($customer, $vendor, $address, $request);

        $voucher = null;
        if ($request->has('voucher_id') && $request->get('voucher_id')) {
            $voucher = Voucher::find($request->get('voucher_id'));
        }

        return [$customer, $vendor, $serviceType, $service, $voucher];
    }

    private function handleServiceCreationWithObjects(Vendor $vendor, ServicesType $serviceType, Service $service, $paymentMethod, string $paymentMethodType, bool $schedule, ?Voucher $voucher = null): array
    {
        $customer = $this->fetchCustomer();

        if ($voucher) {
            // Gate autoritativo do cupão. Corre dentro da transação da reserva: o lock na
            // linha do voucher serializa reservas concorrentes com o mesmo cupão, para que
            // a 2ª só recontar os usos DEPOIS de a 1ª ter gravado o VoucherUsage (fecha a
            // corrida ao limite max_uses). Espelha as validações de ValidateVoucherController.
            $voucher = Voucher::whereKey($voucher->getKey())->lockForUpdate()->first();

            if (! $voucher || ! $voucher->isValid()) {
                throw new VoucherNotUsable('Este cupão não está ativo ou expirou.');
            }

            if (! $voucher->canBeUsedBy($customer)) {
                throw new VoucherNotUsable('Já utilizou este cupão o número máximo de vezes permitido.');
            }

            // Gate autoritativo do tipo de serviço (imediato vs agendado). Só aplica quando
            // o voucher tem restrição configurada — um voucher sem valid_services continua
            // válido para ambos os tipos (comportamento atual preservado).
            if (! empty($voucher->valid_services) && ! $voucher->isValidForServiceType($schedule)) {
                throw new VoucherNotUsable('Este cupão não é válido para este tipo de serviço.');
            }
        }

        // $originalAmount = $this->calculateServicePrice($service->serviceType, $service->address, $vendor, $schedule);

        // A quantidade vem do serviço já construído (createService), e não do
        // request: é o valor que fica gravado e o que o cliente vai pagar tem
        // de ser calculado sobre exatamente esse.
        $total = $this->calculateTransaction($vendor, $serviceType, $schedule, $voucher, false, null, (int) ($service->quantity ?? 1));

        $service->amount = $total['amount'];
        $service->amount_for_vendor = $total['amount_for_vendor'];
        // Distância do pricing (agendado: morada de agenda; imediato: GPS) — sobrepõe a distância
        // GPS inicial de createService() para o valor exibido/push seguir a mesma regra do preço.
        $service->distance = $total['distance'];
        $service->original_amount = $total['original_amount'];
        $service->discount_amount = $total['discount_amount'];
        $service->voucher_id = $voucher?->id;
        $service->payment_status = PaymentStatus::PENDING;
        $service->credit_used = $total['balance_total_used'];

        // Invariante financeira: a plataforma nunca pode cobrar ao cliente menos do que paga
        // ao profissional (comissão negativa). O cálculo acima já garante isto, mas esta guarda
        // é a barreira final — dentro da transação, um throw faz rollback e nada é cobrado.
        if ($service->amount < $service->amount_for_vendor) {
            \Log::error('Service pricing invariant violated (commission would be negative)', [
                'vendor_id' => $vendor->id,
                'amount' => $service->amount,
                'amount_for_vendor' => $service->amount_for_vendor,
                'voucher_id' => $voucher?->id,
            ]);
            throw new Exception('Invalid service pricing: commission would be negative');
        }

        if (! $service->save()) {
            throw new Exception('Error creating service');
        }

        if ($service->is_test) {
            $service->payment_status = PaymentStatus::PAID;
            $service->save();

            return compact('service') + ['validationUrl' => null];
        }

        // Consistente com o gate acima: só regista uso para um cupão realmente utilizável
        // (o gate já garante isto; canBeUsedBy mantém a intenção explícita).
        if ($voucher && $voucher->canBeUsedBy($customer)) {
            VoucherUsage::create([
                'voucher_id' => $voucher->id,
                'user_id' => $customer->id,
                'service_id' => $service->id,
                'used_at' => now(),
            ]);
        }

        if ($paymentMethodType === 'credit_card') {
            $validationUrl = $this->processCreditCardPayment($customer, $service, $vendor, $total, $paymentMethod);
        } elseif ($paymentMethodType === 'mbway') {
            $validationUrl = $this->processMbwayPayment($customer, $service, $vendor, $total, $paymentMethod);
        } else {
            throw new Exception('Unsupported payment method type');
        }

        return compact('service', 'validationUrl');
    }

    private function dispatchMbwayPaymentCheckJob(Service $service)
    {
        MbwayPaymentCheckJob::dispatch($service)->delay(
            now()->addSeconds(config('services.request.mbway_payment_check_timeout'))
        );
    }

    private function createService($customer, Vendor $vendor, $address, $request)
    {
        return new Service([
            'customer_id' => $customer->id,
            'vendor_id' => $request->get('vendor_id'),
            'services_type_id' => $request->get('service_type'),
            'quantity' => max(1, (int) $request->get('quantity', 1)),
            'status' => ServiceStatus::PENDING,
            'is_test' => $customer->is_test ?? false,
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
            'price_rate' => $vendor->getRawOriginal('price_rate'),
            'distance' => $vendor->calculateDistance($address),
            // O campo estava a ser enviado pela app e lido em todo o lado
            // (histórico, detalhe do técnico, backoffice) mas nunca era
            // ESCRITO: nascia sempre nulo. Quem escrevia "campainha do 2.º
            // direito" no checkout estava a falar para o vazio.
            'customer_notes' => $request->get('customer_notes'),
        ]);
    }

    /**
     * Passa as fotos do cliente da espera (utilizador) para o serviço criado.
     *
     * Reconfirma dono e coleção em vez de confiar nos ids recebidos: sem isso,
     * bastava adivinhar um id para colar a foto de outra pessoa a um serviço.
     * Falhar aqui não pode derrubar um pagamento já aceite — uma foto que não
     * viaja é um contratempo, perder a cobrança é outra coisa —, por isso o
     * erro é registado e o pedido segue.
     */
    private function attachCustomerPhotos($request, Service $service): void
    {
        $ids = array_filter((array) $request->get('photo_ids', []));
        if (empty($ids)) {
            return;
        }

        try {
            $customer = $service->customer ?? auth('api')->user();
            if (! $customer) {
                return;
            }

            $customer->getMedia(CustomerServicePhotosController::PENDING_COLLECTION)
                ->whereIn('id', $ids)
                ->take(CustomerServicePhotosController::MAX_PHOTOS)
                ->each(fn ($media) => $media->move($service, 'customer'));
        } catch (Exception $exception) {
            \Log::warning('Falha a anexar fotos do cliente ao serviço', [
                'service_id' => $service->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function createScheduleIfRequested(OpenServiceRequest $request, Vendor $vendor, Service $service): void
    {
        if (! $request->boolean('scheduled')) {
            return;
        }

        $scheduleData = $request->input('schedule', []);
        $scheduledDay = $scheduleData['scheduled_day'] ?? null;

        $serviceType = ServicesType::find($service->services_type_id);
        $scheduledTimeStart = Carbon::parse($scheduleData['scheduled_time_start']);
        $scheduledTimeEnd = $scheduledTimeStart->copy()->addMinutes((int) $serviceType->time);

        $scheduleExists = $vendor->schedules()
            ->where('customer_id', $service->customer_id)
            ->where('scheduled_day', $scheduledDay)
            ->where('service_type_id', $service->services_type_id)
            ->where('scheduled_time_start', $scheduledTimeStart)
            ->where('scheduled_time_end', $scheduledTimeEnd)
            ->where('is_pending', true)
            ->exists();

        if ($scheduleExists) {
            throw new Exception('Schedule already exists');
        }

        $schedule = $vendor->schedules()->create([
            'customer_id' => $service->customer_id,
            'scheduled_day' => Carbon::parse($scheduledDay),
            'service_type_id' => $service->services_type_id,
            'service_id' => $service->id,
            'scheduled_time_start' => $scheduledTimeStart,
            'scheduled_time_end' => $scheduledTimeEnd,
            'is_pending' => true,
        ]);

        if ($vendor->scheduleAvailable()->where('auto_accept', '=', true)->where('is_enabled', '=', true)->exists()) {
            $schedule->update(['is_pending' => false]);
            AcceptScheduleEvent::dispatch($service->customer_id, ['schedule_id' => $schedule->id, 'service_id' => $service->id]);
            \App\Events\Vendor\Schedule\AcceptScheduleEvent::dispatch($service->customer_id, ['schedule_id' => $schedule->id, 'service_id' => $service->id]);
            $this->acceptService->acceptSchedule($service);
            ServiceScheduledEvent::dispatch($schedule->vendor->user->id, ['id' => $schedule->id, 'service_id' => $service->id]);
        } else {
            CreateScheduleEvent::dispatch($vendor->user->id, ['id' => $schedule->id, 'service_id' => $service->id]);
        }
    }

    public function checkPaymentStatus(Service $service)
    {
        try {
            // Only the owner may poll/advance their own service's payment state.
            if ($service->customer_id !== auth()->user()->id) {
                throw new ServiceNotFound;
            }

            // Idempotency guard: the customer app polls this endpoint every 10s while waiting for
            // payment confirmation. Without this guard, each poll would re-run the payment flow and
            // fire another vendor push, producing many duplicate notifications. Once the service is
            // already PAID, just return the current state without re-notifying.
            if ($service->payment_status === PaymentStatus::PAID) {
                return ApiSuccessResponse::make([
                    'service' => $service->formatDataForCustomer(),
                ]);
            }

            // Desfecho MBWay já decidido: devolver 402 para a app navegar para o ecrã de recusa
            // (o force-check do user deve mostrar o estado real, não "ainda pendente").
            if (in_array($service->status, [ServiceStatus::REFUSED_MBWAY, ServiceStatus::EXPIRED_MBWAY], true)) {
                throw new PaymentRefused;
            }

            // Restantes estados terminais (canceled/archived/…) nunca são revividos pelo check.
            if (MbwayPaymentResolver::isTerminal($service)) {
                throw new PaymentNotComplete;
            }

            // Consulta ao Payshop FORA do lock — é uma chamada HTTP externa e lenta.
            $service->loadMissing('paymentOrder');
            $service->paymentOrder?->updateData();

            // Transição sob lock: o MbwayPaymentCheckJob corre em paralelo com o mesmo serviço;
            // sem lock ambos podiam confirmar e materializar a agenda/notificar o vendor 2x.
            $outcome = \DB::transaction(function () use ($service) {
                $locked = Service::whereKey($service->getKey())->lockForUpdate()->first();
                $locked->loadMissing('paymentOrder');

                if ($locked->payment_status === PaymentStatus::PAID) {
                    return 'already_paid';
                }

                if (MbwayPaymentResolver::isTerminal($locked)) {
                    return 'terminal';
                }

                // Recusa/abandono de um pagamento de CARTÃO em validação 3DS: estados terminais-
                // falhados no Payshop. RESTRITO a Pending3DS de propósito — o fluxo MBWay fica em
                // Pending e tem o seu próprio tratamento de recusa (REFUNDED → markRefused, abaixo);
                // sem esta guarda, um MBWay recusado por EXPIRED/USER_CANCELLED entraria aqui e
                // ficaria num estado inconsistente (Pending + CANCELED, sem Observer/notificação).
                // Marcar já o desfecho (Pending3DS → CANCELED, como o FailureValidationController)
                // para a app receber 402 e ir ao ecrã de recusa — sem isto, um cartão recusado caía
                // no ramo 'pending' (400) e ficava preso no ecrã de espera até ao timeout.
                $cardFailedStatuses = [
                    Status::REFUSED,
                    Status::THREEDS_EXPIRED,
                    Status::USER_CANCELLED,
                    Status::EXPIRED,
                    Status::FRAUD,
                    Status::BLACKLISTED,
                    Status::CANCELLED,
                ];

                if ($locked->status === ServiceStatus::PENDING_3DS
                    && in_array($locked->paymentOrder?->status, $cardFailedStatuses, true)) {
                    $locked->payment_status = PaymentStatus::CANCELED;
                    $locked->status = ServiceStatus::CANCELED;
                    $locked->status_justification = 'internal/services.cancel.description';
                    $locked->save();

                    return 'refused';
                }

                // NOTE: Status::CREATED ("Payment Order Initiated") means nothing was authorized/captured
                // yet — it must NOT be treated as paid, or an abandoned 3DS/unprocessed MBWay order would be
                // marked PAID and exposed to the vendor. Mirror SuccessController's stricter list.
                if (! in_array($locked->paymentOrder?->status, [Status::SUCCESS, Status::PENDING_CONFIRMATION, Status::REFUNDED])) {
                    return 'pending';
                }

                if ($locked->paymentOrder->status === Status::REFUNDED) {
                    // Recusado no banco: marcar já o desfecho (não esperar pelo job) — o Observer
                    // liberta o hold e a app recebe 402 imediatamente.
                    app(MbwayPaymentResolver::class)->markRefused($locked);

                    return 'refused';
                }

                // F-05: materializar a agenda ANTES de gravar PAID e DENTRO da transação (espelha
                // MbwayPaymentCheckJob::confirmPayment). Se a materialização lançar, o rollback anula
                // o markPaid → o serviço nunca fica "PAID mas não materializado"; o próximo poll/job
                // repete (MaterializePendingSchedule é idempotente via guard scheduleExists).
                app(MaterializePendingSchedule::class)->handle($locked);
                app(MbwayPaymentResolver::class)->markPaid($locked);

                return 'confirmed_now';
            });

            return match ($outcome) {
                'confirmed_now', 'already_paid' => ApiSuccessResponse::make([
                    'service' => $service->refresh()->formatDataForCustomer(),
                ]),
                'refused' => throw new PaymentRefused,
                default => throw new PaymentNotComplete,
            };
        } catch (Exception $e) {
            return new ApiErrorResponse($e, 'Payment not completed', 400);
        }
    }

    private function storePendingScheduleData(OpenServiceRequest $request, Service $service): void
    {
        if (! $request->boolean('scheduled')) {
            return;
        }

        $scheduleData = $request->input('schedule', []);

        $service->pending_schedule_data = [
            'scheduled' => true,
            'schedule' => $scheduleData,
        ];
        $service->save();
    }

    private function cardIsExpired(?int $expireMonth, $expireYear): bool
    {
        if (! $expireMonth || ! $expireYear) {
            return true;
        }

        $month = (int) $expireMonth;

        $yearInt = (int) $expireYear;
        $year = $yearInt < 100 ? 2000 + $yearInt : $yearInt;

        if ($month < 1 || $month > 12) {
            return true;
        }

        $expiresAtEndOfMonth = Carbon::create($year, $month, 1, 0, 0, 0, config('app.timezone'))
            ->endOfMonth();

        return Carbon::now(config('app.timezone'))->greaterThan($expiresAtEndOfMonth);
    }
}
