<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClearDeletedUsersCommand extends Command
{
    protected $signature = 'clear:deleted-users';

    public function handle(): void
    {
        User::onlyTrashed()
            ->where('deleted_at', '<', now()->subDay())
            ->where('email', 'not like', 'anon-%@anonymized.invalid') // ainda não anonimizado
            ->each(function (User $user) {
                try {
                    DB::transaction(function () use ($user) {
                        // 1) Anonimizar PII + libertar a FK users->payshop_payment_methods.
                        //    'name' é coluna VIRTUAL (CONCAT first/last) — recalcula-se sozinha.
                        $user->forceFill([
                            'first_name' => 'Utilizador',
                            'last_name' => 'Removido',
                            'email' => "anon-{$user->id}@anonymized.invalid",
                            'phone_number' => null,
                            'phone_number_verified_at' => null,
                            'email_verified_at' => null,
                            'nif' => null,
                            'date_birthday' => null,
                            'gender_id' => null,
                            'remember_token' => null,
                            'default_payment_method_id' => null,
                        ])->save();

                        // 2) Relações com PII.
                        $user->devices()->forceDelete();
                        $user->addresses()->forceDelete();
                        $user->billingInfo()->forceDelete();
                        $user->phoneNumberValidationCodes()->forceDelete();
                        $user->impersonationCodes()->forceDelete();

                        // 3) Payshop: manter as ORDERS (retenção contabilística/fiscal), libertar
                        //    a FK e apagar os MÉTODOS de pagamento (holder/last4/phone = PII).
                        DB::table('payshop_payments_orders')
                            ->where('user_id', $user->id)
                            ->update(['payment_method_id' => null]);
                        DB::table('payshop_payment_methods')
                            ->where('user_id', $user->id)
                            ->delete();

                        // NÃO se faz forceDelete(): a linha fica anonimizada + soft-deleted e as
                        // orders ficam retidas. Evita a FK RESTRICT que antes bloqueava a remoção
                        // e cumpre a retenção fiscal + o RGPD (dados anonimizados).
                    });
                } catch (\Throwable $e) {
                    // Um utilizador bloqueado não deve abortar toda a anonimização noturna.
                    Log::warning('clear:deleted-users: skipped user', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
