<?php

namespace App\Console\Commands\Vendors;

use App\Models\Vendor;
use App\Notifications\Vendor\DinheiroRetidoNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Lembra o técnico de que tem dinheiro retido por um dado que só ele pode dar.
 *
 * Desde 13/10/2026 o técnico trabalha os três primeiros serviços só com os
 * documentos: o IBAN, a morada fiscal e a AT ficam para depois. Até os dar, o
 * dinheiro desses serviços fica na carteira dele (Vendor::payoutHoldReason) e
 * a fatura não sai (FaturasEmAtraso). A app já lho mostra, mas só a quem a abre.
 *
 * Uma vez por semana, no máximo. Sem prazo nem perda: o dinheiro é dele e
 * continua a ser (a perda saiu a 03/10/2026, decisão do André).
 */
class LembrarDinheiroRetidoCommand extends Command
{
    protected $signature = 'vendors:lembrar-dinheiro-retido {--dry-run : Só mostra quem seria lembrado}';

    protected $description = 'Lembra os técnicos com dinheiro retido por falta de IBAN, morada fiscal ou AT';

    /** O que falta e depende do técnico. `invoice_pending` é da Piquet: não se lembra ninguém disso. */
    private const RAZOES_DO_TECNICO = ['iban_missing', 'fiscal_address_missing', 'at_user_missing'];

    private const DIAS_ENTRE_LEMBRETES = 7;

    public function handle(): int
    {
        $lembrados = 0;

        Vendor::query()
            ->whereHas('user.wallet', fn ($q) => $q->where('balance', '>', 0))
            ->with('user.wallet')
            ->each(function (Vendor $vendor) use (&$lembrados) {
                // Também quando só PARTE do saldo espera (o resto paga-se): essa
                // parte continua presa até ele dar o que falta.
                $razao = $vendor->payoutHoldReason();

                if (! $vendor->user || ! in_array($razao, self::RAZOES_DO_TECNICO, true)) {
                    return;
                }

                // A proteção que sobrevive a um deploy é a linha em
                // `notifications` (o arranque do contentor limpa a cache) —
                // ver AvisarOnlineSemLocalizacaoCommand.
                $lembradoHaPouco = $vendor->user->notifications()
                    ->where('type', DinheiroRetidoNotification::class)
                    ->where('created_at', '>=', now()->subDays(self::DIAS_ENTRE_LEMBRETES))
                    ->exists();

                if ($lembradoHaPouco) {
                    return;
                }

                // O mesmo número que a app mostra como retido.
                $valor = number_format(((int) $vendor->payout_on_hold_amount) / 100, 2, ',', ' ').' €';

                if ($this->option('dry-run')) {
                    $this->line("lembraria vendor {$vendor->id}: {$valor} ({$razao})");
                    $lembrados++;

                    return;
                }

                // A linha em `notifications` só aparece quando a fila processa o
                // aviso; até lá é esta chave que impede um segundo.
                if (! Cache::add("dinheiro-retido:{$vendor->id}", true, now()->addDays(self::DIAS_ENTRE_LEMBRETES))) {
                    return;
                }

                $vendor->user->notify(new DinheiroRetidoNotification($valor, $razao));
                $lembrados++;
            });

        $this->info("Lembrados: {$lembrados}.");

        return self::SUCCESS;
    }
}
