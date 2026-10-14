<?php

namespace App\Console\Commands;

use App\Models\Wallet\WalletCredit;
use App\Notifications\Customer\CreditoAExpirarNotification;
use App\Services\Carteira\Convites;
use Illuminate\Console\Command;

/**
 * Avisa o cliente uma semana antes de o crédito de convites expirar.
 *
 * Diária. Um aviso por cliente, com o que sobra de todos os créditos dele que
 * expiram nessa semana, e uma só vez por crédito (`expiry_warned_at`).
 *
 * O crédito de quem foi convidado só serve no primeiro serviço pago (ver
 * Convites::creditoDoAmigoServe): a quem já pagou um, não há nada a lembrar —
 * avisá-lo de um crédito que não pode usar seria uma promessa falsa.
 */
class AvisarCreditoAExpirarCommand extends Command
{
    protected $signature = 'carteira:avisar-expiracao {--dry-run : Só mostra quem seria avisado}';

    protected $description = 'Avisa os clientes cujo crédito de convites expira na próxima semana.';

    public const DIAS_ANTES = 7;

    public function handle(Convites $convites): int
    {
        $avisados = 0;

        WalletCredit::query()
            ->usable()
            ->whereNull('expiry_warned_at')
            ->where('expires_at', '<=', now()->addDays(self::DIAS_ANTES))
            ->with('user')
            ->get()
            ->groupBy('user_id')
            ->each(function ($creditos) use ($convites, &$avisados) {
                $user = $creditos->first()->user;

                if (! $user || $user->trashed()) {
                    return;
                }

                $avisar = $creditos->reject(
                    fn (WalletCredit $c) => $c->reason === Convites::MOTIVO_AMIGO && $convites->jaPagou($user)
                );

                if ($avisar->isNotEmpty()) {
                    $valor = (int) $avisar->sum('remaining');
                    $expiraEm = $avisar->min('expires_at');

                    if ($this->option('dry-run')) {
                        $this->line(sprintf('avisaria o cliente %d: %.2f € a expirar a %s', $user->id, $valor / 100, $expiraEm->format('d/m')));
                        $avisados++;

                        return;
                    }

                    $user->notify(new CreditoAExpirarNotification(
                        $valor,
                        $expiraEm,
                        $avisar->contains(fn (WalletCredit $c) => $c->reason === Convites::MOTIVO_AMIGO),
                    ));
                    $avisados++;
                }

                if (! $this->option('dry-run')) {
                    // Também os que não se avisaram (crédito de amigo que já não
                    // serve): a decisão está tomada, não se volta a ela amanhã.
                    WalletCredit::whereIn('id', $creditos->pluck('id'))->update(['expiry_warned_at' => now()]);
                }
            });

        $this->info("Avisados: {$avisados}.");

        return self::SUCCESS;
    }
}
