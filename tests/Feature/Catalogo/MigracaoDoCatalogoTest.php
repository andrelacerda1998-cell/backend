<?php

namespace Tests\Feature\Catalogo;

use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A migração que traduz o catálogo para francês, espanhol e inglês.
 *
 * Testa-se aqui e não à mão na base local porque os dois riscos desta
 * migração são silenciosos: traduzir a linha errada e não traduzir nada.
 * Nenhum dos dois dá erro — só se descobrem quando um cliente francês abre
 * a app e vê metade do ecrã em português.
 */
class MigracaoDoCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private function migracao(): object
    {
        return require database_path('migrations/2026_09_29_160000_traduzir_o_catalogo.php');
    }

    /** O mapa real, tal como vai para produção. */
    private function mapa(): array
    {
        return json_decode(file_get_contents(database_path('data/catalogo-traduzido.json')), true) ?: [];
    }

    private function nomeCru(string $tabela, int $id): array
    {
        return json_decode(DB::table($tabela)->where('id', $id)->value('name') ?? '{}', true) ?: [];
    }

    public function test_casa_pelo_texto_portugues_e_nao_pelo_id(): void
    {
        // Ids trocados de propósito: em produção não coincidem com os desta
        // base, e uma migração que casasse por id traduzia o serviço errado
        // sem dar erro nenhum.
        $area = OperationArea::factory()->create(['name' => ['pt-pt' => 'CANALIZAÇÃO']]);

        $this->migracao()->up();

        $nomes = $this->nomeCru('operation_areas', $area->id);

        $this->assertSame('PLOMBERIE', $nomes['fr']);
        $this->assertSame('FONTANERÍA', $nomes['es']);
        $this->assertSame('PLUMBING', $nomes['en']);
        $this->assertSame('CANALIZAÇÃO', $nomes['pt-pt'], 'o português nunca é tocado');
    }

    public function test_um_espaco_a_mais_no_fim_nao_deixa_o_texto_por_traduzir(): void
    {
        // Três tópicos da base real têm um espaço no fim e no ficheiro de
        // traduções a chave veio sem ele. A casar por string exacta ficavam
        // em português no meio das alíneas traduzidas.
        $area = OperationArea::factory()->create(['name' => ['pt-pt' => '  CANALIZAÇÃO ']]);

        $this->migracao()->up();

        $this->assertSame('PLOMBERIE', $this->nomeCru('operation_areas', $area->id)['fr'] ?? null);
    }

    public function test_nao_escreve_por_cima_do_que_ja_la_estava(): void
    {
        // Onze serviços já tinham inglês escrito à mão no backoffice.
        $area = OperationArea::factory()->create([
            'name' => ['pt-pt' => 'CANALIZAÇÃO', 'en' => 'Plumbing & Heating'],
        ]);

        $this->migracao()->up();

        $this->assertSame('Plumbing & Heating', $this->nomeCru('operation_areas', $area->id)['en']);
    }

    public function test_texto_desconhecido_fica_como_estava(): void
    {
        $area = OperationArea::factory()->create(['name' => ['pt-pt' => 'ÁREA QUE NÃO EXISTE NO MAPA']]);

        $this->migracao()->up();

        $nomes = $this->nomeCru('operation_areas', $area->id);

        $this->assertSame(['pt-pt'], array_keys($nomes));
    }

    public function test_os_topicos_do_servico_ficam_traduzidos_item_a_item(): void
    {
        $mapa = $this->mapa();
        $topico = array_key_first(array_filter(
            $mapa,
            fn ($t, $pt) => ! empty($t['fr']) && ! empty($t['es']) && mb_strlen($pt) > 25,
            ARRAY_FILTER_USE_BOTH
        ));

        $tipo = ServicesType::factory()->create([
            'name' => ['pt-pt' => 'Serviço de teste'],
            'includes' => [$topico],
            'excludes' => [],
        ]);

        $this->migracao()->up();

        $item = json_decode($tipo->fresh()->getRawOriginal('includes'), true)[0];

        $this->assertSame($topico, $item['pt-pt']);
        $this->assertSame($mapa[$topico]['fr'], $item['fr']);
        $this->assertSame($mapa[$topico]['es'], $item['es']);
    }

    public function test_o_down_tira_o_que_esta_migracao_pos(): void
    {
        $area = OperationArea::factory()->create(['name' => ['pt-pt' => 'CANALIZAÇÃO']]);

        $migracao = $this->migracao();
        $migracao->up();
        $migracao->down();

        $this->assertSame(['pt-pt'], array_keys($this->nomeCru('operation_areas', $area->id)));
    }

    public function test_o_down_nao_apaga_o_ingles_que_nao_foi_ele_que_escreveu(): void
    {
        // Um down que destrói dados que não criou não é reversão, é outra
        // perda — e só se dava por ela quando alguém fosse procurar o nome.
        $area = OperationArea::factory()->create([
            'name' => ['pt-pt' => 'CANALIZAÇÃO', 'en' => 'Plumbing & Heating'],
        ]);

        $migracao = $this->migracao();
        $migracao->up();
        $migracao->down();

        $nomes = $this->nomeCru('operation_areas', $area->id);

        $this->assertSame('Plumbing & Heating', $nomes['en']);
        $this->assertArrayNotHasKey('fr', $nomes);
    }

    public function test_o_mapa_cobre_os_tres_idiomas_em_todas_as_entradas(): void
    {
        $semTraducao = [];

        foreach ($this->mapa() as $pt => $traducoes) {
            foreach (['fr', 'es', 'en'] as $idioma) {
                if (empty($traducoes[$idioma])) {
                    $semTraducao[] = "$pt ($idioma)";
                }
            }
        }

        $this->assertSame([], $semTraducao);
    }

    public function test_aparar_as_chaves_do_mapa_nao_cria_colisoes(): void
    {
        // Se dois textos diferentes se tornassem iguais ao aparar, um deles
        // passaria a levar a tradução do outro.
        $mapa = $this->mapa();

        $aparadas = [];
        foreach (array_keys($mapa) as $chave) {
            $aparadas[trim($chave)] = true;
        }

        $this->assertCount(count($mapa), $aparadas);
    }
}
