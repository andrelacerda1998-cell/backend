<?php

namespace App\Http\Controllers\Api\Vendor\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\VendorScheduleSearch;
use Illuminate\Http\Request;

class AutoAcceptController extends Controller
{
    /**
     * Ativa/desativa a auto-aceitação do vendor a partir da Home.
     * Espelha a ToggleVendorAutoAcceptAction do Filament: altera SOMENTE
     * auto_accept, uniformemente nas linhas de agenda, e reindexa o índice.
     */
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $vendor = $request->user()->vendor;

        $vendor->scheduleAvailable()->update(['auto_accept' => $validated['enabled']]);

        // O bulk update() não dispara o ScheduleAvailableObserver — reindexa manualmente.
        VendorScheduleSearch::find($vendor->id)?->searchable();

        return new ApiSuccessResponse([
            'auto_accept' => (bool) $validated['enabled'],
        ]);
    }
}
