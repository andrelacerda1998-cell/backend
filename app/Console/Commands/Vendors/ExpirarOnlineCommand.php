<?php

namespace App\Console\Commands\Vendors;

use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\Vendor;
use App\Notifications\Vendor\OnlineExpirouNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Passa a Offline quem está "Online" sem mandar a localização há dias.
 *
 * O PROBLEMA (diagnósticos de 06 e 07/10/2026): 50 técnicos Online, 46 sem
 * localização há mais de uma semana. O "Online" era um interruptor que nunca
 * expirava. Isso tinha três custos:
 *
 *  - os convites agendados iam para eles. Os três agendados de setembro e
 *    outubro convidaram dez técnicos; os dez deixaram expirar, sem uma recusa.
 *    E como o ranking não olha para a atividade, eram eles que ocupavam os
 *    lugares das ondas, à frente de quem tinha aberto a app nesse dia;
 *  - o backoffice e a procura diziam "50 Online" quando havia quatro;
 *  - o aviso do #142 ("abre a app") repetia-se para sempre a quem já não a
 *    usa, e insistir ensina a desligar as notificações.
 *
 * O aviso do #142 continua a ser o primeiro passo: avisa ao fim de uma hora,
 * no máximo de seis em seis. Isto é o segundo, ao fim de
 * `services.request.online_expira_horas` (72 h: cobre um fim de semana, e
 * entretanto o técnico foi avisado várias vezes).
 *
 * Fica de fora quem tem um serviço em curso: não se pode pôr Offline a meio
 * de um (a app também o recusa, ver StatusController), e quem tem um
 * agendado aceite comprometeu-se. Ficam de fora também as contas de teste,
 * que servem para demonstrações.
 *
 * Só de dia (8h–21h, hora de Lisboa), como o aviso: quem expira à noite
 * recebe a notificação de manhã, sem ser acordado.
 */
class ExpirarOnlineCommand extends Command
{
    protected $signature = 'vendors:expirar-online
                            {--dry-run : Mostra quem passaria a Offline, sem mexer em nada}';

    protected $description = 'Passa a Offline os técnicos Online sem localização há mais de online_expira_horas.';

    public const HORAS_POR_OMISSAO = 72;

    public const HORA_DE_INICIO = 8;

    public const HORA_DE_FIM = 21;

    /** Estados em que o técnico tem trabalho em mãos. */
    public const EM_CURSO = [ServiceStatus::ACCEPTED, ServiceStatus::ARRIVED, ServiceStatus::FINISHED];

    public function handle(): int
    {
        $hora = (int) now('Europe/Lisbon')->format('G');

        if ($hora < self::HORA_DE_INICIO || $hora >= self::HORA_DE_FIM) {
            $this->info('Fora de horas: ninguém expira.');

            return self::SUCCESS;
        }

        $horas = (int) config('services.request.online_expira_horas', self::HORAS_POR_OMISSAO);
        $limite = now()->subHours($horas);

        $expirados = 0;

        Vendor::query()
            ->where('status', StatusVendor::ONLINE)
            ->whereHas('user', fn (Builder $q) => $q->where('is_test', false))
            ->where(fn (Builder $q) => $q
                ->whereHas('currentLocation', fn (Builder $l) => $l->where('updated_at', '<', $limite))
                // Nunca mandou localização: conta desde a última vez que a
                // ficha mudou — pôr-se Online grava-a, por isso quem acabou
                // de o fazer não expira já.
                ->orWhere(fn (Builder $semPosicao) => $semPosicao
                    ->whereDoesntHave('currentLocation')
                    ->where('updated_at', '<', $limite)))
            ->whereDoesntHave('services', fn (Builder $s) => $s->whereIn('status', self::EM_CURSO))
            ->with('user')
            ->chunkById(100, function ($lote) use (&$expirados, $horas) {
                foreach ($lote as $vendor) {
                    if ($this->option('dry-run')) {
                        $this->line("passaria a Offline o vendor {$vendor->id}");
                        $expirados++;

                        continue;
                    }

                    // Pelo modelo, e não por um UPDATE em massa: o `save()`
                    // é o que atualiza o índice de pesquisa (Scout) e o
                    // histórico de auditoria. Um UPDATE direto deixava o
                    // índice a dizer Online, que é metade do problema.
                    $vendor->status = StatusVendor::OFFLINE;
                    $vendor->save();

                    $vendor->user?->notify(new OnlineExpirouNotification($horas));
                    $expirados++;
                }
            });

        $this->info("Passaram a Offline: {$expirados}.");

        return self::SUCCESS;
    }
}
