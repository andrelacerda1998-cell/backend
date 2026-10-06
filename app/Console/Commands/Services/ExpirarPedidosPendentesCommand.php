<?php

namespace App\Console\Commands\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceRefusedEvent;
use App\Models\Service;
use App\Services\Common\Services\RefuseService;
use App\Settings\MatchingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fecha pedidos diretos que o profissional deixou passar.
 *
 * PORQUE É QUE ISTO PASSOU A EXISTIR: a app do técnico mostra um contador e
 * diz-lhe "tens 120 segundos para aceitar". Não era verdade. O servidor nunca
 * expirou um pedido direto -- o `AcceptService` só verifica se o serviço ainda
 * está PENDING, sem olhar ao tempo -- e um pedido continuava aceitável horas
 * depois de o ecrã lhe ter dito que o tempo acabou. O contador era uma ficção
 * da interface.
 *
 * Pior do que a ficção: do lado do CLIENTE, um pedido pago ficava pendurado
 * para sempre num profissional que nunca respondeu, com o dinheiro cativo e
 * sem nada que o libertasse.
 *
 * O DINHEIRO SEGUE O CAMINHO DA RECUSA, e não um caminho novo. Reutiliza-se o
 * `RefuseService` inteiro: libertar a autorização (ou reembolsar, se já foi
 * capturada), devolver o crédito promocional, apagar o agendamento, libertar o
 * voucher de uso único. Escrever uma segunda versão disto era garantir que as
 * duas divergiam no dia em que uma fosse corrigida.
 *
 * O QUE NÃO É IGUAL A UMA RECUSA: a justificação. Não responder porque se
 * estava a conduzir, a dormir ou sem rede não é recusar — e a taxa de aceitação
 * ignora estes (ver StatsController). Sem essa separação, ligar este comando
 * fazia a percentagem de toda a gente cair sozinha, da noite para o dia, por
 * uma regra que ninguém lhes explicou.
 */
class ExpirarPedidosPendentesCommand extends Command
{
    protected $signature = 'services:expirar-pedidos-pendentes {--ensaio : Mostra o que faria, sem mexer em nada}';

    protected $description = 'Expira pedidos diretos que o profissional não respondeu dentro da janela.';

    public function handle(MatchingSettings $settings): int
    {
        $ensaio = (bool) $this->option('ensaio');

        /**
         * A janela vem das DEFINIÇÕES, as mesmas que o matching usa. Escrever
         * 120 aqui dava um terceiro sítio para o número se desalinhar — já
         * bastou a app ter ficado nos 60 quando o servidor subiu para 120.
         */
        $janelaImediato = max(1, (int) $settings->vendor_response_seconds_immediate);
        $janelaAgendado = max(1, (int) $settings->vendor_response_seconds_scheduled);

        $pendentes = Service::query()
            ->where('status', ServiceStatus::PENDING)
            ->where('payment_status', PaymentStatus::PAID)
            // Sem profissional atribuído não há prazo a correr: o pedido ainda
            // está em seleção, e quem manda aí é o `matching:advance`.
            ->whereNotNull('vendor_id')
            ->get();

        $expirados = 0;
        $falhados = 0;

        foreach ($pendentes as $servico) {
            $janela = $servico->schedule_id || $servico->schedule ? $janelaAgendado : $janelaImediato;
            $fim = $servico->created_at?->copy()->addSeconds($janela);

            if (! $fim || $fim->isFuture()) {
                continue;
            }

            if ($ensaio) {
                $this->line(sprintf(
                    '  [ensaio] #%d (vendor %d) — criado há %ds, janela %ds',
                    $servico->id,
                    $servico->vendor_id,
                    (int) $servico->created_at->diffInSeconds(now()),
                    $janela,
                ));
                $expirados++;

                continue;
            }

            try {
                // O `refuse()` volta a ler o serviço e só age se ainda estiver
                // PENDING — se o técnico aceitou no segundo entretanto, não faz
                // nada. É o que torna isto seguro a correr ao minuto.
                (new RefuseService($servico))->refuse(RefuseService::MOTIVO_EXPIRADO);

                $servico->refresh();
                if ($servico->status === ServiceStatus::REFUSED) {
                    ServiceRefusedEvent::dispatch($servico->customer, $servico);
                    $expirados++;
                }
            } catch (\Throwable $e) {
                $falhados++;
                Log::error('[expirar-pedidos] falhou no serviço #'.$servico->id, ['erro' => $e->getMessage()]);
                $this->error('  #'.$servico->id.': '.$e->getMessage());
            }
        }

        $this->info(sprintf(
            '%s%d pedido(s) expirado(s)%s.',
            $ensaio ? '[ensaio] ' : '',
            $expirados,
            $falhados ? ", {$falhados} falha(s)" : '',
        ));

        return self::SUCCESS;
    }
}
