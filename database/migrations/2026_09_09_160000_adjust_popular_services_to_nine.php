<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajusta os destaques da Home de oito para nove, a pedido.
 *
 * Tres mudancas sobre o que a migration anterior semeou: a "Rotura de Cano"
 * sai do primeiro lugar e entra a "Instalacao de Torneira de Casa de Banho",
 * a "Limpeza domestica (T2)" sobe de setimo para SEGUNDO, e entra a "Limpeza
 * de Sofa" no fim.
 *
 * Os dois primeiros lugares — os mais vistos — deixam assim de ser avarias
 * urgentes e passam a ser trabalhos que se compram sem ser por emergencia. As
 * Limpezas passam a ter dois lugares, como a Canalizacao.
 *
 * E uma migration NOVA em vez de uma edicao da anterior de proposito. A
 * anterior ja esta em `main` — reescreve-la deixava quem ja a tivesse corrido
 * sem o ajuste, porque o Laravel guarda o nome do ficheiro e nao o conteudo.
 * As duas correm na mesma ida a producao e o resultado final e este.
 *
 * NAO SOBREPOE CURADORIA HUMANA, pela mesma razao da anterior: so mexe se o
 * que estiver marcado for exatamente o que a migration anterior semeou (ou
 * nada). Se alguem tiver curado pelo backoffice entretanto, fica quieta —
 * quem escolheu a dedo tem mais razao do que uma lista escrita a semanas de
 * distancia.
 */
return new class extends Migration
{
    /** O estado final pretendido: nome exato em pt-pt => ordem na Home. */
    private const NOVE = [
        // Substitui a "Rotura de Cano" (75,00 EUR) que a migration anterior
        // tinha em primeiro. Das duas instalacoes de torneira do catalogo, a
        // de casa de banho — a de lava-loica (id 28) fica de fora.
        'Instalação de Torneira de Casa de Banho' => 1,
        'Limpeza doméstica (T2)' => 2,
        'Desentupimento de Cano' => 3,
        'Abertura de Porta de Entrada' => 4,
        'Reparação de Máquina de Lavar Roupa' => 5,
        'Substituir Tomada' => 6,
        'Instalação de Suporte de TV' => 7,
        'Montar roupeiro (2 Portas)' => 8,
        'Limpeza de Sofá' => 9,
    ];

    /** O que a migration anterior semeou — a assinatura que autoriza mexer. */
    private const OITO_ANTERIORES = [
        'Rotura de Cano' => 1,
        'Desentupimento de Cano' => 2,
        'Abertura de Porta de Entrada' => 3,
        'Reparação de Máquina de Lavar Roupa' => 4,
        'Substituir Tomada' => 5,
        'Instalação de Suporte de TV' => 6,
        'Limpeza doméstica (T2)' => 7,
        'Montar roupeiro (2 Portas)' => 8,
    ];

    public function up(): void
    {
        if (! $this->apenasOSeedAnterior()) {
            return;
        }

        $this->aplicar(self::NOVE);
    }

    /**
     * Repoe os oito da migration anterior, e nao uma lista vazia.
     *
     * Reverter ISTO e voltar ao estado imediatamente anterior. Deixar a Home
     * sem destaques seria reverter as duas migrations de uma vez.
     */
    public function down(): void
    {
        $this->aplicar(self::OITO_ANTERIORES);
    }

    /**
     * Limpa o que estiver marcado e aplica a lista dada, pela ordem dada.
     *
     * @param  array<string, int>  $lista
     */
    private function aplicar(array $lista): void
    {
        // `popular_order` e NOT NULL com default 0 — dai o 0 e nao null.
        DB::table('services_types')
            ->where('is_popular', true)
            ->update(['is_popular' => false, 'popular_order' => 0, 'updated_at' => now()]);

        foreach ($lista as $name => $order) {
            DB::table('services_types')
                ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(name, \'$."pt-pt"\')) = ?', [$name])
                ->whereNull('deleted_at')
                ->update([
                    'is_popular' => true,
                    'popular_order' => $order,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Nada marcado, ou exatamente o seed anterior. Qualquer outra coisa e
     * escolha de alguem e nao se toca.
     */
    private function apenasOSeedAnterior(): bool
    {
        $marcados = DB::table('services_types')
            ->where('is_popular', true)
            ->whereNull('deleted_at')
            ->pluck('name')
            ->map(fn ($json) => json_decode($json, true)['pt-pt'] ?? null)
            ->filter()
            ->sort()
            ->values()
            ->all();

        if (empty($marcados)) {
            return true;
        }

        $esperados = collect(array_keys(self::OITO_ANTERIORES))->sort()->values()->all();

        return $marcados === $esperados;
    }
};
