<?php

namespace App\Services\Operacoes;

use App\Models\GeneralSettings\City;

/**
 * A cidade de um pedido, escrita como a cidade de um técnico.
 *
 * Os dois lados não falam a mesma língua. O técnico escolhe cidades de um
 * catálogo fechado (`cities`, via `vendor_available_cities` — a área de
 * trabalho dele no matching desde 05/10). O pedido traz a cidade que a
 * geocodificação pôs na morada, em texto livre ("Lisboa", "lisboa ", às
 * vezes no campo `locality` em vez de `city`).
 *
 * Para pôr procura e oferta na mesma linha, o nome da morada compara-se com o
 * catálogo como o matching faz quando a cidade ainda não tem coordenadas
 * (City::normalizar: sem acentos, maiúsculas nem pontuação). Se bater, fica o
 * nome do catálogo; se não, fica o primeiro nome com que essa cidade
 * apareceu — "LISBOA" e "Lisboa" fora do catálogo continuam a ser uma linha
 * só. Uma cidade onde ninguém trabalha também tem de aparecer: é justamente
 * essa a lacuna.
 *
 * O que isto NÃO faz: geografia. "Cacém" não passa a "Agualva-Cacém" — isso
 * pedia o centro e o raio de cada cidade (City::contem) e uma conta por
 * pedido, e as cidades ainda não estão todas geocodificadas.
 */
final class CidadeDoPedido
{
    /** @var array<string, string> nome normalizado => nome a mostrar */
    private array $nomes;

    public function __construct()
    {
        $this->nomes = City::query()
            ->pluck('name')
            ->mapWithKeys(fn ($nome) => [City::normalizar((string) $nome) => (string) $nome])
            ->all();
    }

    /** A cidade da morada, em SQL: `city`, ou `locality` quando falta. */
    public static function sql(string $tabela = 'services'): string
    {
        // JSON_UNQUOTE de um `null` JSON dá o texto 'null', não NULL.
        $campo = fn (string $chave) => "NULLIF(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$tabela}.address, '$.{$chave}'))), 'null'), '')";

        return 'COALESCE('.$campo('city').', '.$campo('locality').')';
    }

    /** O nome com que esta cidade aparece ao lado dos técnicos. */
    public function nome(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        if ($texto === '' || strtolower($texto) === 'null') {
            return null;
        }

        return $this->nomes[City::normalizar($texto)] ??= $texto;
    }

    /** As duas formas de escrever a mesma cidade? */
    public static function mesma(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && City::normalizar($a) === City::normalizar($b);
    }
}
