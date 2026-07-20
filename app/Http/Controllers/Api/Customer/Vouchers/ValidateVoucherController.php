<?php

namespace App\Http\Controllers\Api\Customer\Vouchers;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ValidateVoucherController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'voucher_name' => 'required|string|max:30',
            'service_type' => 'required|integer|exists:App\Models\GeneralSettings\ServicesType,id',
            'is_scheduled' => 'boolean',
        ]);

        $voucherName = $request->get('voucher_name');
        $serviceTypeId = $request->get('service_type');
        $isScheduled = $request->get('is_scheduled', false);

        $voucher = Voucher::where('name', $voucherName)->first();

        if (!$voucher) {
            return new ApiErrorResponse(
                ValidationException::withMessages(['voucher_name' => 'Cupão não encontrado.']),
                'Cupão não encontrado.',
                404
            );
        }

        $user = auth()->user();

        // Check if voucher is valid
        if (!$voucher->isValid()) {
            return new ApiErrorResponse(
                ValidationException::withMessages(['voucher_name' => 'Este cupão não está ativo ou expirou.']),
                'Este cupão não está ativo ou expirou.',
                400
            );
        }

        // Check if user can use this voucher
        if (!$voucher->canBeUsedBy($user)) {
            return new ApiErrorResponse(
                ValidationException::withMessages(['voucher_name' => 'Já utilizou este cupão o número máximo de vezes permitido.']),
                'Já utilizou este cupão o número máximo de vezes permitido.',
                400
            );
        }

        // Check if voucher is valid for service type. Só aplica quando o voucher tem
        // restrição configurada — um voucher sem valid_services é válido para ambos os
        // tipos (mesma guarda de OpenServiceController, para validate e checkout alinharem).
        if (! empty($voucher->valid_services) && !$voucher->isValidForServiceType($isScheduled)) {
            $serviceType = $isScheduled ? 'agendado' : 'imediato';
            return new ApiErrorResponse(
                ValidationException::withMessages(['voucher_name' => "Este cupão não é válido para serviços {$serviceType}s."]),
                "Este cupão não é válido para serviços {$serviceType}s.",
                400
            );
        }

        return new ApiSuccessResponse([
            'voucher' => [
                'id' => $voucher->id,
                'name' => $voucher->name,
                'discount_percentage' => $voucher->discount_percentage,
            ],
        ]);
    }
}
