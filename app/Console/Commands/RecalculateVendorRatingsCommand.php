<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use Illuminate\Console\Command;

/**
 * Recalcula `vendor_ratings` a partir de `rating_by_customer`.
 *
 * As linhas antigas foram construídas a partir de `rating_by_vendor` — a nota
 * que o profissional deu ao cliente — e por isso não são corrigíveis, só
 * recalculáveis. A migração esvaziou-as; este comando volta a enchê-las com o
 * que os clientes realmente disseram.
 */
class RecalculateVendorRatingsCommand extends Command
{
    protected $signature = 'vendors:recalculate-ratings';

    protected $description = 'Recalcula as avaliações dos profissionais a partir das notas dadas pelos clientes';

    public function handle(): int
    {
        $total = Vendor::count();

        if ($total === 0) {
            $this->info('Nenhum profissional para recalcular.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Vendor::with('operationAreas')->chunkById(100, function ($vendors) use ($bar) {
            foreach ($vendors as $vendor) {
                $vendor->updateRatting();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $rated = \App\Models\Vendor\Ratings::whereNotNull('average_rating')->count();
        $unrated = \App\Models\Vendor\Ratings::whereNull('average_rating')->count();

        $this->info("Recalculado: {$rated} com avaliações, {$unrated} ainda sem nenhuma.");

        return self::SUCCESS;
    }
}
