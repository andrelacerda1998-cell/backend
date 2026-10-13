<?php

namespace App\Jobs\Services;

use App\Models\Service;
use App\Services\InvoiceXpress\FaturasEmAtraso;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Emite a fatura em atraso de UM serviço (ver FaturasEmAtraso).
 *
 * Um serviço por tarefa, como o CreateInvoiceJob: o PDF demora pelo menos dez
 * segundos a sair (HasInvoiceActions::downloadPdf), e uma tarefa com todos os
 * serviços de um técnico passava do tempo que a fila dá a cada uma. Os de um
 * mesmo técnico vão em cadeia, do mais antigo para o mais recente
 * (FaturasEmAtraso::agendar).
 */
class EmitirFaturaEmAtrasoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Uma tentativa só: a tarefa diária volta a tentar no dia seguinte. */
    public int $tries = 1;

    public function __construct(public Service $service) {}

    public function handle(FaturasEmAtraso $faturas): void
    {
        $resultado = $faturas->emitir($this->service);

        Log::info('[faturas em atraso] '.$resultado, [
            'service_id' => $this->service->id,
            'vendor_id' => $this->service->vendor_id,
        ]);
    }
}
