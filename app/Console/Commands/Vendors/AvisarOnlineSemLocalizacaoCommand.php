<?php

namespace App\Console\Commands\Vendors;

use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\Vendor;
use App\Notifications\Vendor\OnlineSemLocalizacaoNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Avisa quem está "Online" mas há uma hora não manda a localização.
 *
 * O PROBLEMA (diagnóstico de produção de 01/10/2026): 36 técnicos Online e
 * só 1 com localização da última hora. A procura de "Pedir agora" só conta
 * quem tem posição da última hora, por isso devolvia zero em Lisboa inteira.
 * O "Online" é um interruptor no servidor que nunca expira; a localização só
 * chega enquanto a app estiver viva no telemóvel. Quando o técnico a fecha,
 * ou o Android a mata para poupar bateria, continua Online e invisível.
 *
 * O QUE ISTO FAZ: um aviso ao técnico para abrir a app. Nada mais.
 *
 * O QUE ISTO NÃO FAZ, DE PROPÓSITO: não o passa a Offline. "Online" é também
 * o interruptor dos convites agendados, e esses não precisam de posição ao
 * vivo: umas horas sem a app aberta não são motivo para os cortar. Ao fim de
 * DIAS já são — isso é o ExpirarOnlineCommand (07/10), que vem depois deste.
 *
 * Só de dia (8h–21h, hora de Lisboa) e no máximo um aviso a cada
 * HORAS_ENTRE_AVISOS por técnico: um técnico Online à noite sem a app aberta
 * não precisa de ser acordado, e insistir de quinze em quinze minutos ensina-o
 * a desligar as notificações — e aí deixa de receber também os convites.
 */
class AvisarOnlineSemLocalizacaoCommand extends Command
{
    protected $signature = 'vendors:avisar-online-sem-localizacao
                            {--dry-run : Mostra quem seria avisado, sem enviar}';

    protected $description = 'Avisa os técnicos Online que não mandam a localização há mais de uma hora.';

    /** A mesma janela da procura imediata (config services.request.location_update_threshold). */
    public const MINUTOS_SEM_LOCALIZACAO = 60;

    public const HORAS_ENTRE_AVISOS = 6;

    public const HORA_DE_INICIO = 8;

    public const HORA_DE_FIM = 21;

    public function handle(): int
    {
        $hora = (int) now('Europe/Lisbon')->format('G');

        if ($hora < self::HORA_DE_INICIO || $hora >= self::HORA_DE_FIM) {
            $this->info('Fora de horas: ninguém é avisado.');

            return self::SUCCESS;
        }

        $limite = now()->subMinutes((int) config('services.request.location_update_threshold', self::MINUTOS_SEM_LOCALIZACAO));

        $vendors = Vendor::query()
            ->where('status', StatusVendor::ONLINE)
            ->where(fn ($q) => $q
                ->whereDoesntHave('currentLocation')
                ->orWhereHas('currentLocation', fn ($l) => $l->where('updated_at', '<', $limite)))
            // A meio de um serviço o técnico está a usar a app; se a posição
            // não chega, é outro problema, e um aviso destes só o distrai.
            ->whereDoesntHave('services', fn ($s) => $s->whereIn('status', [
                ServiceStatus::ACCEPTED,
                ServiceStatus::ARRIVED,
            ]))
            ->with('user')
            ->get()
            // Quem não pode aceitar trabalho não recebe convites de qualquer
            // forma — dizer-lhe que perde pedidos seria mentir.
            ->filter(fn (Vendor $v) => $v->user && $v->can_accept_service);

        $avisados = 0;

        foreach ($vendors as $vendor) {
            // A PROTEÇÃO QUE SOBREVIVE A UM DEPLOY.
            //
            // A de baixo vive na cache, e o arranque do contentor
            // (infra/entrypoint.sh) corre `cache:clear`: cada deploy apagava-a,
            // e no ciclo seguinte toda a gente voltava a ser avisada. A 06/10
            // saíram 127 avisos para 43 técnicos em quatro horas — três deploys,
            // três rondas — quando a regra é um a cada seis horas. É exactamente
            // a insistência que ensina a desligar as notificações, e com elas
            // os convites.
            //
            // O aviso já fica gravado (canal `database`), por isso é aí que se
            // pergunta. Antes do `--dry-run`, para ele dizer a verdade sobre
            // quem seria avisado.
            if ($this->avisadoHaPouco($vendor)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("avisaria vendor {$vendor->id}");
                $avisados++;

                continue;
            }

            // Cache::add só grava se a chave não existir: dois processos ao
            // mesmo tempo nunca mandam dois avisos.
            //
            // Fica, por cima da verificação acima: o aviso vai pela fila, e a
            // linha em `notifications` só aparece quando a fila o processa.
            // Durante esses segundos é esta chave que impede um segundo aviso.
            if (! Cache::add("online-sem-localizacao:{$vendor->id}", true, now()->addHours(self::HORAS_ENTRE_AVISOS))) {
                continue;
            }

            $vendor->user->notify(new OnlineSemLocalizacaoNotification);
            $avisados++;
        }

        $this->info("Avisados: {$avisados}.");

        return self::SUCCESS;
    }

    /** Recebeu este aviso nas últimas HORAS_ENTRE_AVISOS, segundo o que ficou gravado. */
    private function avisadoHaPouco(Vendor $vendor): bool
    {
        return $vendor->user->notifications()
            ->where('type', OnlineSemLocalizacaoNotification::class)
            ->where('created_at', '>=', now()->subHours(self::HORAS_ENTRE_AVISOS))
            ->exists();
    }
}
