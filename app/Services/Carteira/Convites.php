<?php

namespace App\Services\Carteira;

use App\Enums\Services\ServiceStatus;
use App\Models\Referral\Referral;
use App\Models\Referral\ReferralCode;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet\WalletCredit;
use App\Notifications\Customer\ConviteRecompensaNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Programa de convites: 5 € para o amigo, 5 € para quem convidou.
 *
 * - O amigo põe o código (no ecrã da Carteira ou no campo do código do
 *   checkout) e recebe 5 € na Carteira, válidos 60 dias, que só se gastam num
 *   serviço de 30 € ou mais enquanto ainda não tiver nenhum serviço pago.
 * - Quem convidou recebe 5 €, válidos 6 meses, quando esse amigo fecha (e
 *   paga) um serviço de 30 € ou mais.
 * - O dinheiro sai da carteira da Piquet (CarteiraDoCliente::creditarConvites).
 *
 * Os 30 €: abaixo disso a comissão não cobre os 5 €.
 * "Amigo novo" = nunca pagou um serviço, nem nessa conta nem com o mesmo
 * telemóvel noutra conta (quem já usou a Piquet não abre outra conta para os
 * 5 €). Decisões de 08/10/2026 — ver PIQUET_CONVITES_PLANO.md.
 */
class Convites
{
    public const VALOR = 500;

    public const MINIMO_DO_SERVICO = 3000;

    public const DIAS_DO_AMIGO = 60;

    public const MESES_DE_QUEM_CONVIDA = 6;

    public const LIMITE_POR_ANO = 10;

    public const MOTIVO_AMIGO = 'convite_convidado';

    public const MOTIVO_QUEM_CONVIDA = 'convite_convidante';

    /** Parte ao acaso: sem letras que se confundem a ditar ou a ler (O/0, I/1/L). */
    private const ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly CarteiraDoCliente $carteira) {}

    // ------------------------------------------------------------- códigos

    /** O código do cliente; cria-o na primeira vez (ex.: "ANA" + 3 ao acaso). */
    public function codigoDe(User $user): ReferralCode
    {
        $existente = ReferralCode::where('user_id', $user->id)->first();
        if ($existente) {
            return $existente;
        }

        $prefixo = Str::of(Str::ascii((string) $user->first_name))->upper()->replaceMatches('/[^A-Z]/', '')->substr(0, 3)->value();
        // O nome fica como é ("RUI", não "RUJ"): reconhece-se ao ler. As letras
        // ambíguas só se evitam na parte ao acaso, que é a que se copia mal.

        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $codigo = $prefixo.$this->aoAcaso(6 - strlen($prefixo));
            if (! ReferralCode::where('code', $codigo)->exists()) {
                try {
                    return ReferralCode::create(['user_id' => $user->id, 'code' => $codigo]);
                } catch (\Illuminate\Database\QueryException) {
                    // Corrida com outro pedido ao mesmo tempo: tenta outro, ou devolve o que ganhou.
                    if ($ganhou = ReferralCode::where('user_id', $user->id)->first()) {
                        return $ganhou;
                    }
                }
            }
        }

        throw new \RuntimeException('Não foi possível gerar um código de convite único.');
    }

    private function aoAcaso(int $n): string
    {
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $s .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return $s;
    }

    public static function normalizar(string $codigo): string
    {
        return Str::upper(preg_replace('/\s+/', '', $codigo));
    }

    public function encontrarCodigo(string $codigo): ?ReferralCode
    {
        return ReferralCode::where('code', self::normalizar($codigo))->first();
    }

    // --------------------------------------------------------------- regras

    /** Já pagou um serviço: tem pelo menos um fechado (e não é de teste). */
    public function jaPagou(User $user): bool
    {
        return Service::where('customer_id', $user->id)
            ->where('status', ServiceStatus::CLOSED)
            ->where('is_test', false)
            ->exists();
    }

    /** Outra conta (mesmo apagada) com o mesmo telemóvel já pagou um serviço. */
    private function telemovelJaPagou(User $user): bool
    {
        if (blank($user->phone_number)) {
            return false;
        }

        $outras = User::withTrashed()
            ->where('phone_number', $user->phone_number)
            ->where('id', '!=', $user->id)
            ->pluck('id');

        return $outras->isNotEmpty() && Service::whereIn('customer_id', $outras)
            ->where('status', ServiceStatus::CLOSED)
            ->where('is_test', false)
            ->exists();
    }

    /** Recompensas que quem convida já ganhou nos últimos 12 meses. */
    public function recompensasNoAno(User $quemConvida): int
    {
        return Referral::where('referrer_user_id', $quemConvida->id)
            ->where('status', Referral::CONCLUIDO)
            ->where('completed_at', '>=', now()->subYear())
            ->count();
    }

    /**
     * O amigo põe o código. Devolve o convite criado; lança ConviteRecusado com
     * a razão, em texto para mostrar, se não puder.
     */
    public function aplicar(User $amigo, string $codigo): Referral
    {
        $codigoDoDono = $this->encontrarCodigo($codigo);
        if (! $codigoDoDono) {
            throw new ConviteRecusado('nao_existe');
        }

        $quemConvida = $codigoDoDono->user;
        if (! $quemConvida || $quemConvida->id === $amigo->id) {
            throw new ConviteRecusado('proprio');
        }
        if (filled($amigo->phone_number) && $amigo->phone_number === $quemConvida->phone_number) {
            throw new ConviteRecusado('proprio');
        }
        if (Referral::where('referred_user_id', $amigo->id)->exists()) {
            throw new ConviteRecusado('ja_usou');
        }
        if ($this->jaPagou($amigo) || $this->telemovelJaPagou($amigo)) {
            throw new ConviteRecusado('nao_e_novo');
        }
        // Só convida quem já experimentou a Piquet: corta as contas criadas só para convidar.
        if (! $this->jaPagou($quemConvida)) {
            throw new ConviteRecusado('dono_sem_servicos');
        }
        if ($this->recompensasNoAno($quemConvida) >= self::LIMITE_POR_ANO) {
            throw new ConviteRecusado('limite');
        }

        return DB::transaction(function () use ($amigo, $quemConvida, $codigoDoDono) {
            $convite = Referral::create([
                'referrer_user_id' => $quemConvida->id,
                'referred_user_id' => $amigo->id,
                'referred_phone' => $amigo->phone_number,
                'code' => $codigoDoDono->code,
                'status' => Referral::PENDENTE,
            ]);

            $this->carteira->creditarConvites(
                $amigo,
                self::VALOR,
                now()->addDays(self::DIAS_DO_AMIGO),
                self::MOTIVO_AMIGO,
                $convite,
            );

            return $convite;
        });
    }

    /**
     * Verificação para quem ainda não tem conta (checkout sem sessão): o código
     * existe e pode ser usado? Não aplica nada — o convite só se cria quando a
     * conta existe (guest/register). Com o telemóvel, já recusa quem com esse
     * número já pagou um serviço, para não prometer 5 € que depois não vêm.
     */
    public function verificarSemConta(string $codigo, ?string $telefone = null): ReferralCode
    {
        $codigoDoDono = $this->encontrarCodigo($codigo);
        if (! $codigoDoDono || ! $codigoDoDono->user) {
            throw new ConviteRecusado('nao_existe');
        }

        $quemConvida = $codigoDoDono->user;
        if (! $this->jaPagou($quemConvida)) {
            throw new ConviteRecusado('dono_sem_servicos');
        }
        if ($this->recompensasNoAno($quemConvida) >= self::LIMITE_POR_ANO) {
            throw new ConviteRecusado('limite');
        }

        if (filled($telefone)) {
            if ($telefone === $quemConvida->phone_number) {
                throw new ConviteRecusado('proprio');
            }
            $contas = User::withTrashed()->where('phone_number', $telefone)->get();
            foreach ($contas as $conta) {
                if ($this->jaPagou($conta)) {
                    throw new ConviteRecusado('nao_e_novo');
                }
                if (Referral::where('referred_user_id', $conta->id)->exists()) {
                    throw new ConviteRecusado('ja_usou');
                }
            }
        }

        return $codigoDoDono;
    }

    /**
     * O crédito de boas-vindas do amigo só se gasta num serviço de 30 € ou mais,
     * e só enquanto ele não tiver nenhum serviço pago. Chamado pela
     * CarteiraDoCliente para decidir que créditos contam num pagamento.
     */
    public function creditoDoAmigoServe(User $user, ?int $valorDoServico): bool
    {
        return $valorDoServico !== null
            && $valorDoServico >= self::MINIMO_DO_SERVICO
            && ! $this->jaPagou($user);
    }

    // ---------------------------------------------------------- recompensa

    /**
     * Um serviço fechou e foi pago. Se for o primeiro (de 30 € ou mais) de um
     * amigo convidado, quem convidou ganha os 5 €.
     */
    public function aoFecharServico(Service $service): void
    {
        if ($service->is_test || $service->status !== ServiceStatus::CLOSED) {
            return;
        }
        if ((int) $service->original_amount < self::MINIMO_DO_SERVICO) {
            return;
        }

        DB::transaction(function () use ($service) {
            $convite = Referral::where('referred_user_id', $service->customer_id)
                ->where('status', Referral::PENDENTE)
                ->lockForUpdate()
                ->first();

            if (! $convite) {
                return;
            }

            $quemConvida = $convite->referrer;
            if (! $quemConvida || $quemConvida->trashed()) {
                $convite->update(['status' => Referral::ANULADO, 'cancel_reason' => 'quem convidou apagou a conta', 'canceled_at' => now(), 'first_service_id' => $service->id]);

                return;
            }

            if ($this->recompensasNoAno($quemConvida) >= self::LIMITE_POR_ANO) {
                $convite->update(['status' => Referral::SEM_RECOMPENSA, 'first_service_id' => $service->id, 'completed_at' => now()]);

                return;
            }

            $convite->update(['status' => Referral::CONCLUIDO, 'first_service_id' => $service->id, 'completed_at' => now()]);

            $this->carteira->creditarConvites(
                $quemConvida,
                self::VALOR,
                now()->addMonths(self::MESES_DE_QUEM_CONVIDA),
                self::MOTIVO_QUEM_CONVIDA,
                $convite,
            );

            DB::afterCommit(fn () => $quemConvida->notify(new ConviteRecompensaNotification($convite)));
        });
    }

    /**
     * O primeiro serviço do amigo foi cancelado depois de fechado (reembolso).
     * O convite deixa de valer: o que quem convidou ainda não gastou dos 5 €
     * volta à Piquet. O que já gastou fica — não se cobra nada a ninguém.
     */
    public function aoAnularServico(Service $service): void
    {
        DB::transaction(function () use ($service) {
            $convite = Referral::where('first_service_id', $service->id)
                ->where('status', Referral::CONCLUIDO)
                ->lockForUpdate()
                ->first();

            if (! $convite) {
                return;
            }

            $convite->update(['status' => Referral::ANULADO, 'cancel_reason' => 'primeiro serviço reembolsado', 'canceled_at' => now()]);

            $credito = WalletCredit::where('source_type', $convite->getMorphClass())
                ->where('source_id', $convite->id)
                ->where('reason', self::MOTIVO_QUEM_CONVIDA)
                ->first();

            if ($credito) {
                $this->carteira->recolher($credito);
            }
        });
    }

    /**
     * Anular um convite à mão (abuso). Fecha os créditos dele que ainda não
     * foram gastos — o do amigo e, se já tinha sido concluído, o de quem
     * convidou. O que já foi gasto fica: não se cobra nada a ninguém.
     */
    public function anular(Referral $convite, string $motivo): void
    {
        DB::transaction(function () use ($convite, $motivo) {
            $convite->update(['status' => Referral::ANULADO, 'cancel_reason' => mb_substr($motivo, 0, 120), 'canceled_at' => now()]);

            WalletCredit::where('source_type', $convite->getMorphClass())
                ->where('source_id', $convite->id)
                ->whereNull('expired_at')
                ->get()
                ->each(fn (WalletCredit $c) => $this->carteira->recolher($c));
        });
    }

    // -------------------------------------------------------------- resumo

    /** O que o ecrã "Convida um amigo" mostra. */
    public function resumo(User $user): array
    {
        $convites = Referral::where('referrer_user_id', $user->id)->get(['status']);
        $ganhos = (int) WalletCredit::where('user_id', $user->id)->where('reason', self::MOTIVO_QUEM_CONVIDA)->sum('amount');

        return [
            'code' => $this->codigoDe($user)->code,
            'can_invite' => $this->jaPagou($user),
            'reward_amount' => self::VALOR,
            'minimum_service_amount' => self::MINIMO_DO_SERVICO,
            'friends_joined' => $convites->count(),
            'friends_pending' => $convites->where('status', Referral::PENDENTE)->count(),
            'friends_completed' => $convites->whereIn('status', [Referral::CONCLUIDO, Referral::SEM_RECOMPENSA])->count(),
            'earned' => $ganhos,
            'earned_formated' => number_format($ganhos / 100, 2, '.', ' '),
            'rewards_left_this_year' => max(0, self::LIMITE_POR_ANO - $this->recompensasNoAno($user)),
            'used_a_code' => Referral::where('referred_user_id', $user->id)->exists(),
        ];
    }
}
