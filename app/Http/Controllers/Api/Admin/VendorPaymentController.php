<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Mail\Vendor\PaymentSentMail;
use App\Models\Vendor;
use App\Notifications\Vendor\PaymentSentNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Pagamentos a vendors — migrado do Filament (App\Filament\Pages\VendorPayments).
 *
 * IMPORTANTE: isto NÃO transfere dinheiro a sério. O saldo de cada vendor é
 * ledger interno (bavix/laravel-wallet); "pagar" aqui envia o email/notificação
 * "pagamento enviado" e zera o saldo -- o admin faz a transferência bancária
 * manualmente (por isso a lista mostra o IBAN) e só depois clica em pagar. É
 * exatamente o que o Filament já faz, replicado tal e qual (mesmas classes de
 * Mail/Notification, mesmo meta da transação).
 */
class VendorPaymentController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $vendors = Vendor::whereHas('user.wallet', fn ($q) => $q->where('balance', '>', 0))
            ->with(['user.wallet'])
            ->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($vendors->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $vendors->currentPage(),
                'last_page' => $vendors->lastPage(),
                'per_page' => $vendors->perPage(),
                'total' => $vendors->total(),
            ],
        ]);
    }

    public function pay(Vendor $vendor): ApiSuccessResponse|ApiErrorResponse
    {
        $wallet = $vendor->user->wallet;

        if ($wallet->balance <= 0) {
            return new ApiErrorResponse(null, 'Este vendor não tem saldo por pagar.', 409);
        }

        $amount = $wallet->balance_float;

        // Mesma sequência do Filament (App\Filament\Pages\VendorPayments::table()):
        // email, notificação (push + BD) e só depois o débito do saldo.
        Mail::to($vendor->user->email)->send(new PaymentSentMail($vendor, $amount));
        $vendor->user->notify(new PaymentSentNotification($amount));
        $wallet->withdraw($wallet->balance, [
            'type' => 'Debit',
            'description' => 'Transfer to account',
            'admin_description' => 'Transfer to account',
            'class' => Vendor::class,
            'id' => $vendor->getKey(),
            'admin_id' => auth()->id(),
        ]);

        return ApiSuccessResponse::make([
            'vendor_id' => $vendor->id,
            'amount_paid' => (float) $amount,
        ]);
    }

    private function present(Vendor $vendor): array
    {
        // NÃO usar vendor->name / user->name -- ver nota em SystemProfitController
        // e VendorDocumentController sobre User::setNameAttribute() nunca gravar
        // a coluna 'name'. first_name/last_name são as colunas reais.
        $user = $vendor->user;

        return [
            'id' => $vendor->id,
            'vendor_name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null,
            // Espaçado a cada 4 caracteres, tal como o Filament (formatStateUsing).
            'iban' => $vendor->iban ? trim(preg_replace('/(\w{4})(?=\w)/', '$1 ', $vendor->iban)) : null,
            // balance_float vem como string (contrato do bavix/laravel-wallet) --
            // sem o cast, o JSON manda "150.00" como string em vez de número.
            'balance' => (float) $user->wallet->balance_float,
        ];
    }
}
