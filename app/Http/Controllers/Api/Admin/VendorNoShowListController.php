<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Common\Services\VendorNoShowPolicy;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A fila de faltas para o backoffice.
 *
 * Três listas, porque são três perguntas diferentes:
 *  - AVISOS: a hora está mesmo a chegar (ou passou há pouco) e o técnico ainda
 *    não disse que vai a caminho. Ainda não é falta — é o momento de lhe
 *    ligar, enquanto um telefonema ainda salva o serviço.
 *  - SUSPEITAS: serviços cuja hora passou e o técnico nunca chegou a "Cheguei".
 *    É o que o `services:detect-no-show` escala para ops — aqui fica a lista
 *    com o botão, em vez de um email que se perde.
 *  - DECLARADAS: as faltas já confirmadas, com o valor cobrado, para se ver o
 *    que foi feito e responder a contestações.
 *
 * Consumido pelo backoffice (ver App\Http\Middleware\AdminApiToken).
 */
class VendorNoShowListController extends Controller
{
    /** Mais do que isto é lixo antigo, não uma fila de trabalho. */
    private const SUSPECT_MAX_AGE_DAYS = 7;

    /**
     * O aviso acende este tempo antes da hora marcada e apaga-se este tempo
     * depois dela. Antes da hora ainda há tempo de um telefonema pôr o
     * técnico a caminho; depois dela entram também o `services:detect-no-show`
     * (T+10 ao técnico, T+20 a ops) e a lista das suspeitas, que continua.
     */
    public const MINUTOS_DO_AVISO = 15;

    public function __invoke(Request $request): ApiSuccessResponse
    {
        return new ApiSuccessResponse([
            'suspected' => $this->suspected(),
            'declared' => $this->declared(),
            'avisos' => $this->avisos($request->boolean('incluir_testes')),
            'penalty_ratio' => VendorNoShowPolicy::PENALTY_RATIO,
        ]);
    }

    /**
     * POSSÍVEL FALTA: a hora marcada é daqui a menos de MINUTOS_DO_AVISO (ou
     * passou há menos do que isso), o serviço está aceite ou agendado, e o
     * técnico não marcou "Estou a caminho" (`on_the_way_at` vazio — a mesma
     * marca que a CancellationPolicy e o "cliente ausente" usam).
     *
     * O que passou há menos de um quarto de hora aparece também nas suspeitas:
     * é de propósito, são as duas perguntas ao mesmo tempo.
     *
     * As contas de teste ficam de fora, salvo `?incluir_testes=1` — a mesma
     * regra das Operações ao vivo. As outras duas listas ficam como estavam.
     */
    private function avisos(bool $testes): array
    {
        $agora = now();
        $hoje = now('Europe/Lisbon');

        return Service::query()
            ->where('is_test', $testes)
            ->whereIn('status', VendorNoShowPolicy::OPEN_STATUSES)
            ->whereNull('vendor_no_show_at')
            ->whereNotNull('vendor_id')
            ->whereNull('on_the_way_at')
            ->whereHas('schedule', fn ($q) => $q
                ->where('is_pending', false)
                // A janela pode atravessar a meia-noite (de Lisboa).
                ->whereBetween('scheduled_day', [
                    $hoje->copy()->subDay()->toDateString(),
                    $hoje->copy()->addDay()->toDateString(),
                ]))
            ->with(['schedule', 'serviceType', 'customer', 'vendor.user'])
            ->get()
            ->map(fn (Service $service) => [
                'servico' => $service,
                'inicio' => Service::instanteDe($service->schedule->scheduled_day, $service->schedule->scheduled_time_start),
            ])
            ->filter(fn (array $a) => $a['inicio'] instanceof CarbonInterface && $a['inicio']->between(
                $agora->copy()->subMinutes(self::MINUTOS_DO_AVISO),
                $agora->copy()->addMinutes(self::MINUTOS_DO_AVISO),
            ))
            ->sortBy(fn (array $a) => $a['inicio']->getTimestamp())
            ->values()
            ->map(fn (array $a) => [
                ...$this->present($a['servico']),
                // Negativo quando a hora já passou.
                'minutes_to_start' => (int) round($agora->diffInMinutes($a['inicio'], false)),
            ])
            ->all();
    }

    private function suspected(): array
    {
        $now = now();

        return Service::query()
            ->whereIn('status', VendorNoShowPolicy::OPEN_STATUSES)
            ->whereNull('vendor_no_show_at')
            ->whereNotNull('vendor_id')
            ->whereHas('schedule', fn ($q) => $q
                ->where('is_pending', false)
                ->where('scheduled_day', '>=', $now->copy()->subDays(self::SUSPECT_MAX_AGE_DAYS)->toDateString())
                ->where('scheduled_day', '<=', $now->toDateString()))
            ->with(['schedule', 'serviceType', 'customer', 'vendor.user'])
            ->get()
            // A hora só se sabe juntando o dia e a hora do agendamento, por isso
            // o filtro "já passou" fica em PHP, como no DetectNoShowCommand.
            ->filter(function (Service $service) use ($now) {
                $schedule = $service->schedule;
                if (! $schedule) {
                    return false;
                }
                $scheduledAt = Carbon::parse($schedule->scheduled_day, 'Europe/Lisbon')
                    ->setTimeFromTimeString($schedule->scheduled_time_start);

                return $scheduledAt->lessThan($now);
            })
            ->sortByDesc(fn (Service $service) => $service->schedule->scheduled_day.' '.$service->schedule->scheduled_time_start)
            ->values()
            ->map(fn (Service $service) => $this->present($service))
            ->all();
    }

    private function declared(): array
    {
        return Service::query()
            ->whereNotNull('vendor_no_show_at')
            ->with(['schedule', 'serviceType', 'customer', 'vendor.user'])
            ->orderByDesc('vendor_no_show_at')
            ->limit(100)
            ->get()
            ->map(fn (Service $service) => $this->present($service))
            ->all();
    }

    private function present(Service $service): array
    {
        $amountForVendor = (int) abs($service->getRawOriginal('amount_for_vendor'));

        return [
            'service_id' => $service->id,
            'status' => $service->status->value,
            'service_type' => $service->serviceType?->getTranslation('name', app()->getLocale()),
            'scheduled_day' => $service->schedule?->scheduled_day,
            'scheduled_time' => $service->schedule?->scheduled_time_start,
            'customer' => $service->customer?->only(['id', 'name', 'phone_number']),
            'vendor' => [
                'id' => $service->vendor?->id,
                'name' => $service->vendor?->user?->name,
                'phone_number' => $service->vendor?->user?->phone_number,
            ],
            'on_the_way_at' => $service->on_the_way_at?->toIso8601String(),
            // Em cêntimos. O valor que o técnico ia receber e o que a falta lhe
            // custaria — para quem carrega no botão saber o que está a decidir.
            'amount_for_vendor' => $amountForVendor,
            'penalty_if_declared' => VendorNoShowPolicy::penaltyAmount($amountForVendor),
            'vendor_no_show_at' => $service->vendor_no_show_at?->toIso8601String(),
            'vendor_no_show_penalty' => $service->vendor_no_show_penalty,
        ];
    }
}
