<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\UpdateFeeSettingsRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Settings\RateSettings;

class FeeSettingsController extends Controller
{
    public function show(RateSettings $rateSettings): ApiSuccessResponse
    {
        return ApiSuccessResponse::make($this->present($rateSettings));
    }

    public function update(UpdateFeeSettingsRequest $request, RateSettings $rateSettings): ApiSuccessResponse
    {
        $data = $request->validated();

        $rateSettings->daytime = $data['daytime'];
        $rateSettings->evening = $data['evening'];
        $rateSettings->night = $data['night'];
        $rateSettings->late_night = $data['late_night'];
        $rateSettings->midnight = $data['midnight'];
        // Guardado em cêntimos, tal como o form do Filament faz via dehydrateStateUsing.
        $rateSettings->kilometer_price = (int) round($data['kilometer_price'] * 100);
        $rateSettings->system_commission = $data['system_commission'];

        $rateSettings->save();

        return ApiSuccessResponse::make($this->present($rateSettings));
    }

    private function present(RateSettings $rateSettings): array
    {
        return [
            'daytime' => $rateSettings->daytime,
            'evening' => $rateSettings->evening,
            'night' => $rateSettings->night,
            'late_night' => $rateSettings->late_night,
            'midnight' => $rateSettings->midnight,
            // Devolvido em euros (não cêntimos), simétrico ao pedido de update.
            'kilometer_price' => $rateSettings->kilometer_price / 100,
            'system_commission' => $rateSettings->system_commission,
        ];
    }
}
