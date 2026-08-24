<?php

namespace App\Http\Controllers\Api\Vendor\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Vendor\VendorUnavailableDay;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dias de indisponibilidade pontual do técnico.
 *
 * A disponibilidade semanal (schedule_available) não permitia dizer "esta
 * quarta não posso": ou se desligava o dia da semana para sempre, ou se
 * recusava pedido a pedido — o que baixa a taxa de aceitação por algo que não
 * é recusa.
 */
class UnavailableDaysController extends Controller
{
    /** Próximos dias marcados (janela alinhada com a da procura: 15 dias). */
    public function index()
    {
        $vendor = auth()->user()->vendor;

        $days = $vendor->unavailableDays()
            ->whereDate('day', '>=', now()->startOfDay())
            ->orderBy('day')
            ->get(['id', 'day', 'reason']);

        return new ApiSuccessResponse(['days' => $days]);
    }

    public function store(Request $request)
    {
        $vendor = auth()->user()->vendor;

        $data = $request->validate([
            // Só daqui para a frente: marcar o passado não muda nada e só
            // confundiria a lista.
            'day' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $day = VendorUnavailableDay::updateOrCreate(
            ['vendor_id' => $vendor->id, 'day' => $data['day']],
            ['reason' => $data['reason'] ?? null],
        );

        return new ApiSuccessResponse(['day' => $day->only(['id', 'day', 'reason'])]);
    }

    public function destroy(Request $request, string $day)
    {
        $vendor = auth()->user()->vendor;

        $request->merge(['day' => $day])->validate([
            'day' => ['required', 'date', Rule::prohibitedIf(false)],
        ]);

        $vendor->unavailableDays()->whereDate('day', $day)->delete();

        return new ApiSuccessResponse(['deleted' => true]);
    }
}
