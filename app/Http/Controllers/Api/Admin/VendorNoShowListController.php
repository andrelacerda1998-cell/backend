<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Common\Services\VendorNoShowPolicy;
use Illuminate\Support\Carbon;

/**
 * A fila de faltas para o backoffice.
 *
 * Duas listas, porque são duas perguntas diferentes:
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

    public function __invoke(): ApiSuccessResponse
    {
        return new ApiSuccessResponse([
            'suspected' => $this->suspected(),
            'declared' => $this->declared(),
            'penalty_ratio' => VendorNoShowPolicy::PENALTY_RATIO,
        ]);
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
