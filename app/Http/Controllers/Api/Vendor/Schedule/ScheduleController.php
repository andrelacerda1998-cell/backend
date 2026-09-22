<?php

namespace App\Http\Controllers\Api\Vendor\Schedule;

use App\DTO\Services\ServiceRequestedData;
use App\Enums\Services\AddressType;
use App\Enums\Services\ServiceStatus;
use App\Events\Customer\Schedule\AcceptScheduleEvent;
use App\Events\Vendor\Schedule\ServiceScheduledEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Schedule\UpdateScheduleAddress;
use App\Http\Requests\Api\Vendor\Schedule\UpdateScheduleAvailability;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Schedule\Schedule;
use App\Models\Schedule\ScheduleDays;
use App\Models\User;
use App\Models\Vendor;
use App\Repository\Schedule\ScheduleRepository;
use App\Services\Common\AddressService;
use App\Services\Common\Services\AcceptService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ScheduleController extends Controller
{
    public function __construct(
        private readonly AddressService $addressService,
        private readonly ScheduleRepository $scheduleRepository,
        private readonly AcceptService $acceptService,
    ) {}

    public function settings(int $userId): ApiSuccessResponse
    {
        // IDOR: ignorar o $userId do path; usar sempre o utilizador autenticado (igual ao update()).
        $scheduleAvailability = $this->scheduleRepository
            ->scheduleAvailableVendorSettings(auth()->user()->id)
            ->toArray();

        return new ApiSuccessResponse($scheduleAvailability);
    }

    /**
     * So a morada de onde o tecnico sai para um servico agendado.
     *
     * Existe a parte do `update()` porque aquele exige os dias da semana. No
     * "completar perfil" ha uma coisa a pedir, nao duas: e desta que o
     * matching precisa para calcular a distancia — e o preco — dos agendados.
     *
     * A escrita e chaveada pelo TIPO, como em toda a parte: com o array vazio
     * o `updateOrCreate` reescrevia a morada fiscal.
     *
     * @throws Exception
     */
    public function updateAddress(UpdateScheduleAddress $request): ApiSuccessResponse|ApiErrorResponse
    {
        $data = $request->validated();
        $geoCoordinates = $this->addressService->getCoordinates($data);

        if (! is_array($geoCoordinates) || empty($geoCoordinates['address_components'] ?? null) || ! isset($geoCoordinates['lat'], $geoCoordinates['lng'])) {
            return new ApiErrorResponse(new Exception('Could not geocode address.'), 'Address is invalid', 400);
        }

        // Derivar o vendor do token, nunca do corpo do pedido (IDOR de escrita).
        $vendor = auth()->user()->vendor()->firstOrFail();

        try {
            $addressData = $this->addressService->transformAddress([
                'address_name' => 'Schedule Address',
                ...$data,
            ], $geoCoordinates);
        } catch (Exception $e) {
            return new ApiErrorResponse($e, 'Address is invalid', 400);
        }

        $vendor->addresses()->updateOrCreate(
            ['address_type' => AddressType::SCHEDULE_ADDRESS],
            [
                ...$addressData,
                'user_id' => $vendor->user->id,
            ],
        );

        return new ApiSuccessResponse(['address' => $addressData]);
    }

    /**
     * @throws Exception
     */
    public function update(UpdateScheduleAvailability $request): ApiSuccessResponse
    {
        $addressRequest = $request->input('address');
        $geoCoordinates = $this->addressService->getCoordinates($addressRequest);

        if (empty($geoCoordinates)) {
            throw new Exception('Unable to geocode the provided address.');
        }

        $addressData = $this->addressService->transformAddress($addressRequest, $geoCoordinates);

        // Derivar o vendor do token, nunca do corpo do pedido (IDOR de escrita).
        $user = User::query()->findOrFail(auth()->user()->id);

        /** @var Vendor $vendor */
        $vendor = $user->vendor()->firstOrFail();
        // Chaveado pelo TIPO — ver AddressController::update. Sem isto, gravar
        // aqui apagava a morada fiscal, de que dependem a facturacao e a
        // activacao do tecnico.
        $vendor->addresses()->updateOrCreate(
            ['address_type' => AddressType::SCHEDULE_ADDRESS],
            [
                ...$addressData,
                'user_id' => $vendor->user->id,
            ],
        );

        $scheduleDays = Cache::rememberForever('schedule_days', fn () => ScheduleDays::all()->pluck('id', 'day_name')->toArray());
        foreach ($request->input('available_days', []) as $dayOfWeek => $dayInformation) {
            $vendor->scheduleAvailable()->updateOrCreate(
                ['day_id' => $scheduleDays[$dayOfWeek]],
                [
                    // Sempre falso: a auto-aceitacao saiu a 15/09/2026.
                    'auto_accept' => false,
                    'time_start' => $dayInformation['time_start'],
                    'time_end' => $dayInformation['time_end'],
                    'is_enabled' => $dayInformation['is_enabled'],
                ],
            );
        }

        return new ApiSuccessResponse;
    }

    public function updateAvailability(Request $request): ApiSuccessResponse
    {
        $user = User::query()->findOrFail(auth()->user()->id);

        /** @var Vendor $vendor */
        $vendor = $user->vendor()->firstOrFail();

        $scheduleDays = Cache::rememberForever('schedule_days', fn () => ScheduleDays::all()->pluck('id', 'day_name')->toArray());
        foreach ($request->input('available_days', []) as $dayOfWeek => $dayInformation) {
            $vendor->scheduleAvailable()->updateOrCreate(
                ['day_id' => $scheduleDays[$dayOfWeek]],
                [
                    'time_start' => $dayInformation['time_start'],
                    'time_end' => $dayInformation['time_end'],
                    'is_enabled' => $dayInformation['is_enabled'],
                ],
            );
        }

        return new ApiSuccessResponse;
    }

    public function schedules(): ApiSuccessResponse
    {
        /** @var User $user */
        $user = auth()->user();
        /** @var Vendor $vendor */
        $vendor = $user->vendor()->first();

        $services = $vendor->services()
            ->whereIn('status', [ServiceStatus::SCHEDULED, ServiceStatus::ACCEPTED])
            ->whereHas('schedule', function ($query) {
                // A janela recua 7 dias para trás de propósito: um agendamento
                // cuja hora passou e que não foi concluído tem de continuar a
                // chegar à app. Antes começava em "hoje" e esses serviços
                // simplesmente desapareciam do ecrã do técnico — nem "em
                // atraso", nem "não compareceste" — e ele acumulava faltas sem
                // nunca saber. A app mostra-os numa secção "Em atraso".
                $query->where('scheduled_day', '>=', Carbon::now()->subDays(7)->startOfDay())
                    ->where('scheduled_day', '<=', Carbon::now()->addDays(15))
                    ->where('is_pending', '=', 0);
            })
            ->get()
            ->map(function ($service) use ($user) {
                $scheduleDate = Carbon::parse($service->schedule->scheduled_day);
                $now = Carbon::now();

                $service->date_label = match (true) {
                    $scheduleDate->isToday() => 'today',
                    $scheduleDate->isTomorrow() => 'tomorrow',
                    $scheduleDate->isSameWeek($now) => 'week',
                    default => 'week',
                };

                return ServiceRequestedData::fromArray($service, $user);
            });

        return new ApiSuccessResponse($services);
    }

    public function pendingSchedules(): ApiSuccessResponse
    {
        $user = auth()->user();
        /** @var Vendor $vendor */
        $vendor = $user->vendor()->first();
        $schedules = $vendor->schedules()
            ->with([
                'service:id,status',
                'serviceType:id,name',
                'customer:users.id,name',
                'customer.addresses' => function ($query) {
                    $query->select('addresses.id', 'addresses.user_id', 'address_name', 'street_name', 'street_number', 'postal_code', 'city', 'state', 'country');
                },
            ])
            ->where('is_pending', '=', 1)
            ->orderBy('scheduled_day', 'asc')
            ->orderBy('scheduled_time_start', 'asc')
            ->get();

        // Esta lista é de LEITURA. Abri-la não aceita nada em nome de ninguém.
        //
        // Até aqui, abrir o ecrã gravava `is_pending = false` em todo o
        // agendamento já pago com mais de 20 minutos, sem o técnico tocar em
        // coisa nenhuma. O dano não era sobretudo mostrar uma aceitação que não
        // houve — era o oposto: o CancelJobWithoutReactionJob, que trata a falta
        // de resposta, tem a guarda `! $schedule->is_pending` e desistia. O
        // serviço ficava PENDING para sempre: nunca aceite, nunca cancelado,
        // nunca reembolsado, e fora da vista dos dois lados.
        //
        // Os dois corriam ao mesmo prazo (services.request.time_accept_scheduled,
        // 1200s), por isso era uma corrida entre o técnico abrir a app e a fila
        // processar o job. E a escrita acontecia ANTES do filtro abaixo, ou seja
        // atingia também agendamentos que nem chegavam a aparecer na resposta.
        //
        // Onde o serviço já estivesse ACCEPTED ou SCHEDULED, somava-se a isso
        // mostrar "accepted" ao cliente e libertar-lhe o telemóvel do técnico
        // (ListSchedulesController) — mas esse não era o caso comum, porque
        // um pedido à espera de resposta tem o serviço em PENDING.
        //
        // Quem não responde deixa o job cancelar. Aceitar é um gesto: storeSchedule().

        $schedules = $schedules->filter(fn ($schedule) => $schedule->service && (
            $schedule->service->status === ServiceStatus::SCHEDULED ||
            $schedule->service->status === ServiceStatus::ACCEPTED
        ))->values();

        return new ApiSuccessResponse($schedules);
    }

    public function storeSchedule(Request $request): ApiSuccessResponse
    {
        $schedule = Schedule::query()->findOrFail($request->input('schedule_id'));

        // Um vendor só pode confirmar/aceitar os seus próprios schedules (mesma proteção de getScheduleData).
        if ($schedule->vendor_id !== auth()->user()->vendor->id) {
            abort(403);
        }

        $schedule->update([
            'is_pending' => false,
        ]);

        AcceptScheduleEvent::dispatch($schedule->customer->id, ['id' => $schedule->id]);

        ServiceScheduledEvent::dispatch($schedule->vendor->user->id, ['id' => $schedule->id, 'service_id' => $schedule->service_id]);

        $this->acceptService->acceptSchedule($schedule->service);

        return new ApiSuccessResponse;
    }

    public function getScheduleData(Schedule $schedule): ApiSuccessResponse
    {
        /** @var User $user */
        $user = auth()->user();
        if ($schedule->vendor_id !== $user->vendor->id) {
            abort(403);
        }

        $service = $schedule->service;
        if (! $service) {
            abort(404);
        }

        $scheduleDate = Carbon::parse($schedule->scheduled_day);
        $now = Carbon::now();

        $service->date_label = match (true) {
            $scheduleDate->isToday() => 'today',
            $scheduleDate->isTomorrow() => 'tomorrow',
            $scheduleDate->isSameWeek($now) => 'week',
            default => 'week',
        };

        $scheduleData = ServiceRequestedData::fromArray($service, $user);

        return ApiSuccessResponse::make($scheduleData);
    }
}
