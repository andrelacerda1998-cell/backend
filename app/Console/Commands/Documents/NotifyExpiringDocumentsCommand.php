<?php

namespace App\Console\Commands\Documents;

use App\Models\Vendor\VendorDocuments;
use App\Notifications\Vendor\DocumentExpiringNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Avisa os técnicos de documentos aprovados prestes a expirar.
 *
 * Regra de negócio: um documento vale ATÉ AO ÚLTIMO DIA DE VALIDADE, INCLUSIVE;
 * a partir do dia seguinte o técnico deixa de poder aceitar serviços
 * (Vendor::allDocumentsVerified -> canAcceptService). Avisa-se a 30, 15, 7, 3 e 1 dias.
 *
 * IDEMPOTÊNCIA: cada aviso enviado é gravado em vendor_document_expiry_notifications,
 * com UNIQUE (vendor_document_id, expiration_date, days_before). O INSERT é feito ANTES
 * do envio: se a linha já existir, o insert falha em silêncio (insertOrIgnore devolve 0)
 * e nada é enviado. Assim, correr o comando N vezes no mesmo dia — ou duas instâncias em
 * paralelo — nunca duplica um push. Ver o comentário da migração para a justificação.
 */
class NotifyExpiringDocumentsCommand extends Command
{
    protected $signature = 'documents:notify-expiring
                            {--dry-run : Mostra o que seria enviado, sem enviar nem gravar}';

    protected $description = 'Avisa os técnicos de documentos aprovados a expirar em 30, 15, 7, 3 ou 1 dias.';

    /** Limiares de aviso, em dias antes da data de expiração. */
    private const THRESHOLDS = [30, 15, 7, 3, 1];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = now()->startOfDay();

        // Mapa data-alvo => limiar. Datas exatas, para não reavisar dias intermédios.
        $targets = [];
        foreach (self::THRESHOLDS as $days) {
            $targets[$today->copy()->addDays($days)->toDateString()] = $days;
        }

        $documents = VendorDocuments::query()
            ->where('status', 'approved')
            ->whereNotNull('expiration_date')
            ->whereIn(DB::raw('DATE(expiration_date)'), array_keys($targets))
            ->with(['type', 'vendor.user'])
            ->get();

        $this->info(sprintf('Documentos em limiar de aviso: %d', $documents->count()));

        $sent = 0;
        $skipped = 0;

        foreach ($documents as $document) {
            $expirationDate = \Illuminate\Support\Carbon::parse($document->expiration_date)->toDateString();
            $days = $targets[$expirationDate] ?? null;

            if ($days === null) {
                continue;
            }

            $user = $document->vendor?->user;

            if (! $user) {
                $this->warn(sprintf('Documento #%d sem técnico/utilizador associado — ignorado.', $document->id));
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('[dry-run] documento #%d (%s) -> técnico #%d, %d dia(s).', $document->id, $document->type?->name ?? '?', $document->vendor->id, $days));
                $sent++;

                continue;
            }

            // Guarda de idempotência: reserva o aviso ANTES de enviar.
            $reserved = DB::table('vendor_document_expiry_notifications')->insertOrIgnore([
                'vendor_document_id' => $document->id,
                'expiration_date' => $expirationDate,
                'days_before' => $days,
                'notified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($reserved === 0) {
                $skipped++;

                continue;
            }

            try {
                $user->notify(new DocumentExpiringNotification($document, $days));
                $sent++;
            } catch (\Throwable $e) {
                // Um técnico sem token de push não deve abortar o lote. A reserva fica
                // gravada de propósito: o aviso seguinte é o do limiar seguinte.
                Log::warning('documents:notify-expiring: falha ao notificar', [
                    'vendor_document_id' => $document->id,
                    'days_before' => $days,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        $this->info(sprintf('Avisos enviados: %d | ignorados (já avisados ou sem destinatário): %d', $sent, $skipped));

        return self::SUCCESS;
    }
}
