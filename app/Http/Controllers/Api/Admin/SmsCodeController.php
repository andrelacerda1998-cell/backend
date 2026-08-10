<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Auth\PhoneNumberValidationCode;
use Illuminate\Http\Request;

/**
 * Códigos SMS — equivalente ao Filament SmsCodeResource. Só leitura, usado
 * pelo suporte para confirmar que código foi enviado a um número (ex: "não
 * recebi o SMS"). No Filament o acesso era restrito a super-admin
 * (canAccess()), mas a admin API não tem sessão/role por pedido -- é um
 * token partilhado (ver App\Http\Middleware\AdminApiToken) -- pelo que essa
 * distinção não é replicável aqui, mesma decisão já tomada em
 * VendorController/CustomerController::deletePaymentMethod.
 *
 * Nota sobre os dados: não existem colunas 'expires_at'/'used_at' -- a
 * validade é calculada em runtime (janela de 5 min a partir de created_at)
 * e um código "usado" é apagado da tabela após verificação com sucesso (ver
 * PhoneLoginSmsService::verifyCode()). Por isso um código já não aparece
 * aqui assim que é validado; o que fica é o histórico de códigos emitidos
 * (usados ou não) até serem consumidos.
 */
class SmsCodeController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = PhoneNumberValidationCode::query()->with('user');

        if ($search = $request->string('search')->trim()->value()) {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('phone_number', 'like', $like)
                    ->orWhereHas('user', function ($uq) use ($like) {
                        $uq->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
            });
        }

        if ($type = $request->string('type')->trim()->value()) {
            $query->where('type', $type);
        }

        $codes = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($codes->items())->map($this->presentSafely(...))->all(),
            'meta' => [
                'current_page' => $codes->currentPage(),
                'last_page' => $codes->lastPage(),
                'per_page' => $codes->perPage(),
                'total' => $codes->total(),
            ],
        ]);
    }

    /** Isola falhas de uma linha (utilizador apagado, tipo com valor inesperado) do resto do feed. */
    private function presentSafely(PhoneNumberValidationCode $code): array
    {
        try {
            return $this->present($code);
        } catch (\Throwable $e) {
            report($e);

            return [
                'id' => $code->id,
                'phone_number' => $code->phone_number,
                'code' => $code->code,
                'type' => is_object($code->type) ? $code->type->value : (string) $code->type,
                'user' => null,
                'created_at' => $code->created_at?->toIso8601String(),
            ];
        }
    }

    private function present(PhoneNumberValidationCode $code): array
    {
        $user = $code->user;
        $userName = $user
            ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null)
            : null;

        return [
            'id' => $code->id,
            'phone_number' => $code->phone_number,
            'code' => $code->code,
            'type' => $code->type->value,
            'user' => $user ? ['id' => $user->id, 'name' => $userName ?? '-'] : null,
            'created_at' => $code->created_at?->toIso8601String(),
        ];
    }
}
