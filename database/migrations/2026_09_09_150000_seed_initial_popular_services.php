<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Semeia a lista inicial de "Serviços populares" da Home.
 *
 * O bloco existe na app desde sempre, mas em producao nenhum tipo de servico
 * estava marcado como `is_popular` — o endpoint devolvia `{"services":[]}` e a
 * app escondia a seccao. Ou seja: a Home nunca chegou a ter destaques, e o
 * cliente cai direto nas categorias sem nada que lhe sugira por onde comecar.
 *
 * A ESCOLHA E EDITORIAL, E NAO BASEADA EM PROCURA. Nao havia dados de volume
 * disponiveis para a fazer — a base de desenvolvimento so tem o catalogo, nao
 * o historico de servicos. O criterio usado foi: cobrir as SETE categorias
 * ativas com um trabalho cada, dando duas a Canalizacao por ser onde estao as
 * avarias urgentes, e preferir dentro de cada categoria o trabalho mais
 * universal em vez do mais caro. Assim que houver numeros de procura real,
 * isto revê-se — e revê-se no backoffice, que e onde estes dois campos vivem
 * (ServicesTypeResource: toggle `is_popular` + `popular_order`).
 *
 * NAO SOBREPOE CURADORIA HUMANA. Se ja houver alguem marcado como popular
 * quando isto correr, a migration nao faz nada. Entre escrever-se isto e
 * chegar a producao pode passar tempo, e alguem pode ter feito a escolha
 * entretanto pelo backoffice — apagar-lha em silencio seria o pior desfecho.
 *
 * Corresponde por NOME e nao por id: os ids do catalogo podem divergir entre
 * ambientes, e um id errado marcava o servico errado na Home de toda a gente.
 *
 * A chave da traducao e `pt-pt` e nao `pt`. A primeira versao disto usava
 * `$.pt` e nao encontrava absolutamente nada — em producao teria passado por
 * uma migration bem sucedida que nao fez nada. O hifen obriga a aspas dentro
 * do caminho JSON.
 */
return new class extends Migration
{
    /** Nome exato em pt => ordem na Home. */
    private const POPULARES = [
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
        if (DB::table('services_types')->where('is_popular', true)->exists()) {
            return;
        }

        foreach (self::POPULARES as $name => $order) {
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
     * Desmarca APENAS os oito desta lista.
     *
     * Um `update(is_popular = false)` a tabela toda apagaria tambem o que
     * tivesse sido curado no backoffice depois disto.
     *
     * `popular_order` volta a 0 e nao a null: a coluna e NOT NULL com default
     * 0 (int unsigned). A primeira versao punha null aqui e o rollback morria
     * com "Column 'popular_order' cannot be null" — uma migration que so se
     * descobre irreversivel no dia em que e precisa reverte-la.
     */
    public function down(): void
    {
        foreach (array_keys(self::POPULARES) as $name) {
            DB::table('services_types')
                ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(name, \'$."pt-pt"\')) = ?', [$name])
                ->update([
                    'is_popular' => false,
                    'popular_order' => 0,
                    'updated_at' => now(),
                ]);
        }
    }
};
