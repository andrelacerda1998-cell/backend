<?php

namespace App\Services\Carteira;

use App\Models\Service;
use App\Models\User;
use App\Models\Wallet\WalletCredit;
use App\Models\Wallet\WalletCreditUsage;
use Bavix\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A Carteira do cliente: Saldo + Crédito de convites.
 *
 * - **Saldo** é a carteira Bavix `default`, que já existia: reembolsos, ou seja
 *   dinheiro do cliente. Não expira. O caminho dele não muda — continua a ser
 *   `$customer->withdraw()` / `$customer->deposit()` com `credit_used`.
 * - **Crédito de convites** é uma segunda carteira Bavix (`convites`). O
 *   dinheiro entra por TRANSFERÊNCIA da carteira do sistema, para as contas da
 *   Piquet baterem certo: quando o serviço fecha, o `CloseService::settle()`
 *   deposita a comissão inteira na carteira do sistema, e a parte paga com
 *   crédito já tinha saído de lá. O que expira volta para lá.
 *
 * Separados porque têm regras diferentes: um cancelamento devolve cada parte à
 * carteira de onde saiu (o crédito de convites nunca se transforma em saldo
 * real) e só o crédito de convites expira.
 *
 * Num pagamento usa-se primeiro o crédito de convites — é o que expira.
 */
class CarteiraDoCliente
{
    public const CONVITES = 'convites';

    /** A carteira dos convites, ou null se o cliente nunca recebeu crédito. */
    public function carteiraConvites(User $user, bool $criar = false): ?Wallet
    {
        $carteira = $user->getWallet(self::CONVITES);

        if (! $carteira && $criar) {
            $carteira = $user->createWallet([
                'name' => 'Crédito de convites',
                'slug' => self::CONVITES,
                'meta' => ['description' => 'Crédito promocional da Piquet. Expira.'],
            ]);
        }

        return $carteira;
    }

    /**
     * Crédito de convites que se pode gastar AGORA, em cêntimos.
     *
     * A soma do que sobra nos créditos dentro do prazo — e não o saldo da
     * carteira. Um crédito que passou o prazo continua na carteira até a tarefa
     * diária o recolher, e não pode ser gasto entretanto. Limitado ao saldo
     * real da carteira, por segurança: nunca se promete gastar o que lá não está.
     */
    public function convitesDisponivel(User $user): int
    {
        $carteira = $this->carteiraConvites($user);
        if (! $carteira) {
            return 0;
        }

        $porPrazo = (int) WalletCredit::where('user_id', $user->id)->usable()->sum('remaining');

        return max(0, min($porPrazo, (int) $carteira->balanceInt));
    }

    /** O Saldo (reembolsos), em cêntimos. */
    public function saldoDisponivel(User $user): int
    {
        return max(0, (int) $user->balanceInt);
    }

    /**
     * Quanto sai de cada parte para pagar `$valor` cêntimos.
     *
     * @return array{convites: int, saldo: int}
     */
    public function repartir(User $user, int $valor): array
    {
        $valor = max(0, $valor);
        $convites = min($this->convitesDisponivel($user), $valor);
        $saldo = min($this->saldoDisponivel($user), $valor - $convites);

        return ['convites' => $convites, 'saldo' => $saldo];
    }

    /**
     * Tira `$valor` do crédito de convites para pagar um serviço, pelos créditos
     * que expiram primeiro, e regista de onde saiu cada cêntimo.
     */
    public function debitarConvites(User $user, Service $service, int $valor): void
    {
        if ($valor <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $service, $valor) {
            $creditos = WalletCredit::where('user_id', $user->id)
                ->usable()
                ->orderBy('expires_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($creditos->sum('remaining') < $valor) {
                // O preço foi calculado com este crédito. Se entretanto
                // desapareceu (expirou a meio do checkout), cobrar menos do que o
                // cliente aceitou pagar ou mais do que tem são ambos errados:
                // rebenta, e a transação do checkout desfaz tudo.
                throw new \RuntimeException('Crédito de convites insuficiente para o valor calculado.');
            }

            $falta = $valor;
            foreach ($creditos as $credito) {
                if ($falta <= 0) {
                    break;
                }
                $parte = min($credito->remaining, $falta);
                $credito->decrement('remaining', $parte);
                WalletCreditUsage::create([
                    'wallet_credit_id' => $credito->id,
                    'service_id' => $service->id,
                    'amount' => $parte,
                ]);
                $falta -= $parte;
            }

            $this->carteiraConvites($user)->withdraw($valor, [
                'type' => 'service_payment',
                'service_id' => $service->id,
            ]);
        });
    }

    /**
     * Devolve ao cliente o que um serviço cancelado, recusado ou expirado tinha
     * gasto da Carteira — cada parte para a carteira de onde saiu.
     *
     * O Saldo (`credit_used`) devolve-se como sempre se devolveu, com o mesmo
     * meta. Quem chama continua a decidir SE devolve (as guardas de
     * idempotência de cada sítio ficam onde estavam).
     *
     * O crédito de convites devolve-se a partir dos usos registados e ainda não
     * devolvidos — não do `referral_credit_used`. Assim uma segunda chamada não
     * devolve duas vezes, e um serviço que nunca chegou a debitar (conta de
     * teste) não devolve dinheiro que nunca saiu.
     */
    public function devolver(Service $service, User $customer, array $metaSaldo): void
    {
        if ($service->credit_used > 0) {
            $customer->deposit($service->credit_used, $metaSaldo);
        }

        $this->devolverConvites($service, $customer);
    }

    public function devolverConvites(Service $service, User $customer): void
    {
        DB::transaction(function () use ($service, $customer) {
            $usos = WalletCreditUsage::where('service_id', $service->id)
                ->whereNull('returned_at')
                ->lockForUpdate()
                ->get();

            if ($usos->isEmpty()) {
                return;
            }

            $paraOCliente = 0;
            $paraOSistema = 0;

            foreach ($usos as $uso) {
                $credito = WalletCredit::whereKey($uso->wallet_credit_id)->lockForUpdate()->first();

                if ($credito && ! $credito->expired_at) {
                    // Volta à linha de onde saiu, com o prazo que já tinha. Se o
                    // prazo passou e a tarefa diária ainda não correu, ela recolhe-o.
                    $credito->increment('remaining', $uso->amount);
                    $paraOCliente += $uso->amount;
                } else {
                    // O crédito expirou enquanto o serviço estava pendente: a
                    // tarefa diária já o fechou e não volta a olhar para ele.
                    // Devolvê-lo ao cliente era dar-lhe crédito sem prazo.
                    $paraOSistema += $uso->amount;
                }

                $uso->update(['returned_at' => now()]);
            }

            if ($paraOCliente > 0) {
                $this->carteiraConvites($customer, criar: true)->deposit($paraOCliente, [
                    'type' => 'service_refund',
                    'service_id' => $service->id,
                ]);
            }

            if ($paraOSistema > 0) {
                system_wallet()->deposit($paraOSistema, [
                    'type' => 'referral_credit_expired',
                    'service_id' => $service->id,
                ]);
            }
        });
    }

    /**
     * Põe crédito de convites na Carteira do cliente, pago pela carteira do
     * sistema.
     *
     * `forceTransfer`: a carteira do sistema pode ficar negativa. O crédito é
     * uma decisão de negócio já tomada; se a carteira do sistema não chegar,
     * isso tem de aparecer nas contas — não pode é falhar o crédito ao cliente.
     */
    public function creditarConvites(
        User $user,
        int $valor,
        Carbon $expiraEm,
        string $motivo,
        ?Model $origem = null,
    ): WalletCredit {
        if ($valor <= 0) {
            throw new \InvalidArgumentException('O crédito tem de ser positivo.');
        }

        return DB::transaction(function () use ($user, $valor, $expiraEm, $motivo, $origem) {
            $credito = WalletCredit::create([
                'user_id' => $user->id,
                'amount' => $valor,
                'remaining' => $valor,
                'expires_at' => $expiraEm,
                'reason' => $motivo,
                'source_type' => $origem?->getMorphClass(),
                'source_id' => $origem?->getKey(),
            ]);

            system_wallet()->forceTransfer($this->carteiraConvites($user, criar: true), $valor, [
                'type' => 'referral_credit',
                'reason' => $motivo,
                'wallet_credit_id' => $credito->id,
            ]);

            return $credito;
        });
    }

    /**
     * Recolhe para a carteira do sistema o crédito de convites fora de prazo.
     *
     * @return array{creditos: int, valor: int} quantos créditos e quantos cêntimos
     */
    public function expirar(?Carbon $agora = null): array
    {
        $agora ??= now();
        $creditos = 0;
        $valor = 0;

        WalletCredit::whereNull('expired_at')
            ->where('expires_at', '<=', $agora)
            ->orderBy('id')
            ->chunkById(100, function ($lote) use (&$creditos, &$valor, $agora) {
                foreach ($lote as $credito) {
                    DB::transaction(function () use ($credito, &$creditos, &$valor, $agora) {
                        $credito = WalletCredit::whereKey($credito->id)->lockForUpdate()->first();
                        if (! $credito || $credito->expired_at) {
                            return;
                        }

                        $sobra = (int) $credito->remaining;
                        $carteira = $sobra > 0 ? $this->carteiraConvites($credito->user) : null;
                        // Nunca mais do que a carteira tem: se alguém mexeu nela
                        // à mão no backoffice, recolhe-se o que houver.
                        $aRecolher = $carteira ? min($sobra, max(0, (int) $carteira->balanceInt)) : 0;

                        if ($aRecolher > 0) {
                            $carteira->transfer(system_wallet(), $aRecolher, [
                                'type' => 'referral_credit_expired',
                                'wallet_credit_id' => $credito->id,
                            ]);
                        }

                        $credito->update(['remaining' => 0, 'expired_at' => $agora]);
                        $creditos++;
                        $valor += $aRecolher;
                    });
                }
            });

        return ['creditos' => $creditos, 'valor' => $valor];
    }
}
