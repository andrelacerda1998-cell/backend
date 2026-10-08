<?php

namespace App\Console\Commands;

use App\Services\Carteira\CarteiraDoCliente;
use Illuminate\Console\Command;

/**
 * Recolhe o crédito de convites fora de prazo para a carteira do sistema.
 *
 * Diária. Entretanto, um crédito fora de prazo já não se gasta (o cálculo do
 * preço só conta os que estão dentro do prazo); isto só arruma o dinheiro.
 */
class ExpirarCreditoDaCarteiraCommand extends Command
{
    protected $signature = 'carteira:expirar';

    protected $description = 'Devolve à Piquet o crédito de convites que passou o prazo.';

    public function handle(CarteiraDoCliente $carteira): int
    {
        $r = $carteira->expirar();

        $this->info("Créditos expirados: {$r['creditos']} · recolhido: ".number_format($r['valor'] / 100, 2, ',', ' ').' €');

        return self::SUCCESS;
    }
}
