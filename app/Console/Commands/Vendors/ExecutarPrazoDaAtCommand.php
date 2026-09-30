<?php

namespace App\Console\Commands\Vendors;

use App\Models\Vendor;
use App\Notifications\Vendor\AtDeadlineForfeitedNotification;
use App\Notifications\Vendor\AtDeadlineWarningNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Executa o prazo dos 5 dias para o subutilizador da AT.
 *
 * Faz duas coisas, por esta ordem:
 *
 *  1. AVISA quem ainda está dentro do prazo (a 3, 1 e 0 dias do fim);
 *  2. EXECUTA a perda para quem o deixou passar.
 *
 * A perda é IRREVERSÍVEL do ponto de vista do saldo, por isso este comando:
 *
 *  - só toca no que foi GANHO a trabalhar (`ganhos_por_pagar`), nunca no
 *    crédito promocional — esse não é pagamento de trabalho e não está em causa;
 *  - grava `at_forfeited_at` e `at_forfeited_amount` no técnico, o que o torna
 *    idempotente: corre duas vezes e a segunda não faz nada;
 *  - deixa rasto na carteira (um levantamento com meta própria) e no log;
 *  - tem `--ensaio`, que mostra exactamente o que faria sem fazer nada.
 *
 * NOTA PARA QUEM MEXER AQUI: isto retira dinheiro que o técnico ganhou a
 * trabalhar. Só é defensável se os termos de serviço o previrem e ele os tiver
 * aceitado. Se essa parte cair, isto passa a ser um problema legal, não um bug.
 */
class ExecutarPrazoDaAtCommand extends Command
{
    protected $signature = 'vendors:executar-prazo-da-at {--ensaio : Mostra o que faria, sem fazer nada}';

    protected $description = 'Avisa e executa o prazo de 5 dias para o subutilizador da AT';

    public function handle(): int
    {
        $ensaio = (bool) $this->option('ensaio');

        if ($ensaio) {
            $this->warn('ENSAIO — nada será alterado.');
        }

        $this->avisar($ensaio);
        $this->executar($ensaio);

        return self::SUCCESS;
    }

    /**
     * Avisos a 3, 1 e 0 dias. Não é spam diário de propósito: um aviso que
     * chega todos os dias deixa de se ler ao terceiro.
     */
    private function avisar(bool $ensaio): void
    {
        $avisados = 0;

        foreach ($this->comPrazoACorrer() as $vendor) {
            $dias = $vendor->dias_ate_ao_prazo_da_at;

            if (! in_array($dias, [3, 1, 0], true)) {
                continue;
            }

            // Não se avisa de uma perda que não vai acontecer: sem aceitação a
            // execução ignora-o, e o aviso seria uma ameaça vazia.
            if (! $vendor->aceitou_os_termos_em_vigor) {
                continue;
            }

            $this->line("  aviso a {$dias} dia(s): vendor #{$vendor->id} ({$vendor->payout_on_hold_amount} cent)");
            $avisados++;

            if (! $ensaio) {
                $vendor->user?->notify(new AtDeadlineWarningNotification($dias, $vendor->payout_on_hold_amount));
            }
        }

        $this->info("Avisos: {$avisados}.");
    }

    private function executar(bool $ensaio): void
    {
        $perdidos = 0;
        $total = 0;

        foreach ($this->comPrazoACorrer() as $vendor) {
            if (! $vendor->prazo_da_at_expirado) {
                continue;
            }

            /*
             * SEGUNDA VERIFICAÇÃO DA ACEITAÇÃO, e não é redundante.
             *
             * O `comecarPrazoDaAtSeNecessario()` já não arranca o relógio a
             * quem não aceitou. Mas um relógio que já esteja a correr não
             * desaparece — e houve uma janela real em que isso aconteceu: o
             * prazo foi para produção às 17:29 de 30/09 e a aceitação só
             * depois. Qualquer terceiro serviço fechado nesse intervalo deixou
             * um relógio a correr contra alguém que nunca viu a cláusula.
             *
             * Verificar no arranque protege os casos novos; verificar AQUI
             * protege os que já existem. É aqui que o dinheiro sai, e é aqui
             * que a pergunta tem de voltar a ser feita.
             */
            if (! $vendor->aceitou_os_termos_em_vigor) {
                $this->line("  ignorado (sem aceitação dos Termos): vendor #{$vendor->id}");

                continue;
            }

            $montante = $vendor->ganhos_por_pagar;

            if ($montante <= 0) {
                continue;
            }

            $this->line("  PERDE: vendor #{$vendor->id} — {$montante} cent");
            $perdidos++;
            $total += $montante;

            if (! $ensaio) {
                $this->retirar($vendor, $montante);
            }
        }

        $this->info("Perdas executadas: {$perdidos} (total {$total} cent).");
    }

    /**
     * Técnicos com o relógio a contar. Filtra em SQL o que dá para filtrar; o
     * resto (`payoutBlocker`, que lê moradas e conta serviços) fica em PHP,
     * sobre um conjunto já pequeno.
     */
    private function comPrazoACorrer()
    {
        return Vendor::whereNotNull('at_deadline_started_at')
            ->whereNull('at_forfeited_at')
            ->with('user.wallet')
            ->cursor()
            ->filter(fn (Vendor $v) => $v->payoutBlocker() === 'at_user_missing');
    }

    /**
     * Retira o montante da carteira do técnico para a da plataforma.
     *
     * Numa transação: se o depósito na carteira do sistema falhar, o
     * levantamento ao técnico desfaz-se. O contrário seria dinheiro a
     * desaparecer de uma carteira sem aparecer na outra.
     */
    private function retirar(Vendor $vendor, int $montante): void
    {
        DB::transaction(function () use ($vendor, $montante) {
            $meta = [
                'type' => 'Debit',
                'description' => __('internal/services.at_deadline.forfeited_description'),
                'admin_description' => 'Prazo da AT expirado — saldo transferido para a plataforma',
                'class' => Vendor::class,
                'id' => $vendor->getKey(),
                'reason' => 'at_deadline_expired',
            ];

            $vendor->user->wallet->withdraw($montante, $meta);
            system_wallet()->deposit($montante, $meta);

            $vendor->forceFill([
                'at_forfeited_at' => now(),
                'at_forfeited_amount' => $montante,
            ])->save();
        });

        Log::warning('Prazo da AT expirado: saldo transferido para a plataforma', [
            'vendor_id' => $vendor->id,
            'amount_cents' => $montante,
            'deadline_started_at' => $vendor->at_deadline_started_at?->toIso8601String(),
        ]);

        $vendor->user?->notify(new AtDeadlineForfeitedNotification($montante));
    }
}
