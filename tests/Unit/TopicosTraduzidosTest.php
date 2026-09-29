<?php

namespace Tests\Unit;

use App\Casts\TranslatableArrayCast;
use Tests\TestCase;

/**
 * Os tópicos de "Inclui" / "Não inclui" de um tipo de serviço.
 *
 * Cada tópico é traduzido individualmente — {"pt-pt": "...", "en": "..."} —
 * e um idioma em falta devolvia STRING VAZIA. O ecrã do serviço mostrava uma
 * linha em branco no meio da lista, sem erro nenhum em lado nenhum: só o
 * cliente é que via. Estes testes prendem o recuo.
 */
class TopicosTraduzidosTest extends TestCase
{
    private function topico(array $idiomas): array
    {
        return [$idiomas];
    }

    public function test_devolve_o_idioma_pedido(): void
    {
        $itens = $this->topico(['pt-pt' => 'Deslocação do técnico', 'fr' => 'Déplacement du pro']);

        $this->assertSame(['Déplacement du pro'], TranslatableArrayCast::getTranslated($itens, 'fr'));
    }

    public function test_sem_o_idioma_pedido_recua_para_portugues(): void
    {
        // Era aqui que aparecia a linha em branco.
        $itens = $this->topico(['pt-pt' => 'Deslocação do técnico', 'en' => 'Travel to the site']);

        $this->assertSame(['Deslocação do técnico'], TranslatableArrayCast::getTranslated($itens, 'fr'));
    }

    public function test_sem_portugues_recua_para_outro_idioma_da_app(): void
    {
        $itens = $this->topico(['en' => 'Travel to the site']);

        $this->assertSame(['Travel to the site'], TranslatableArrayCast::getTranslated($itens, 'fr'));
    }

    public function test_um_topico_so_num_idioma_que_a_app_nao_serve_sai_da_lista(): void
    {
        // Isto não é uma tradução em falta, é dado partido: alguém gravou um
        // tópico num idioma que a app não tem. Mostrá-lo era pôr no ecrã de um
        // cliente texto que ninguém escolheu pôr lá — e o formato do ecrã da
        // agenda do técnico já contava com ele fora.
        $itens = [['pt-pt' => 'Mão de obra'], ['xx_XX' => 'Sem tradução']];

        $this->assertSame(['Mão de obra'], TranslatableArrayCast::getTranslated($itens, 'pt-pt'));
        $this->assertSame(['Mão de obra'], TranslatableArrayCast::getTranslated($itens, 'fr'));
    }

    public function test_o_formato_antigo_de_texto_simples_continua_a_funcionar(): void
    {
        // A base tem 154 serviços com os tópicos assim. Se isto partisse, a
        // app ficava sem "Inclui" nenhum enquanto a migração não corresse.
        $itens = ['Deslocação do técnico ao local', 'Teste de estanqueidade'];

        $this->assertSame($itens, TranslatableArrayCast::getTranslated($itens, 'fr'));
    }

    public function test_um_topico_sem_texto_nenhum_sai_da_lista(): void
    {
        // Melhor uma lista com menos um ponto do que um ponto sem texto ao
        // lado, que parece que falta ali qualquer coisa.
        $itens = [['pt-pt' => 'Vale'], ['pt-pt' => ''], ['en' => '']];

        $this->assertSame(['Vale'], TranslatableArrayCast::getTranslated($itens, 'pt-pt'));
    }

    public function test_uma_lista_vazia_continua_vazia(): void
    {
        $this->assertSame([], TranslatableArrayCast::getTranslated([], 'fr'));
        $this->assertSame([], TranslatableArrayCast::getTranslated(null, 'fr'));
    }
}
