<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Vendor;
use App\Services\InvoiceXpress\FaturasEmAtraso;
use Illuminate\Console\Command;

/**
 * Uma vez por dia: emite as faturas que ficaram por emitir, para os técnicos
 * que entretanto passaram a ter conta de faturação e AT (ver FaturasEmAtraso).
 *
 * Diário e não de hora a hora: um rascunho que o InvoiceXpress recusa finalizar
 * volta a ser tentado a cada corrida, e uma vez por dia chega para um pedido que
 * já está atrasado. Quem precisar já usa o "emitir agora" do backoffice.
 */
class EmitirFaturasEmAtrasoCommand extends Command
{
    protected $signature = 'faturas:emitir-em-atraso {--dry-run : Só mostra o que faria}';

    protected $description = 'Emite as faturas de serviços fechados que ficaram por emitir';

    public function handle(): int
    {
        $vendorIds = Service::query()->porFaturar()->whereNotNull('vendor_id')->distinct()->pluck('vendor_id');

        $prontos = 0;
        $faturas = 0;
        $semDados = 0;

        Vendor::query()->whereIn('id', $vendorIds)->each(function (Vendor $vendor) use (&$prontos, &$faturas, &$semDados) {
            if (! FaturasEmAtraso::podeEmitir($vendor)) {
                $semDados++;

                return;
            }

            $quantas = $this->option('dry-run')
                ? FaturasEmAtraso::doTecnico($vendor)->count()
                : FaturasEmAtraso::agendar($vendor);

            $prontos++;
            $faturas += $quantas;
            $this->line(sprintf('técnico %d: %d por emitir', $vendor->id, $quantas));
        });

        $this->info(sprintf(
            '%d técnico(s) com faturas por emitir: %d prontos (%d faturas%s), %d ainda sem conta de faturação ou AT.',
            $vendorIds->count(),
            $prontos,
            $faturas,
            $this->option('dry-run') ? ', nada enviado: --dry-run' : ', na fila',
            $semDados,
        ));

        return self::SUCCESS;
    }
}
