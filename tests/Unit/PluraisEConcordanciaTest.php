<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Dois textos que concordavam mal e ninguém via.
 *
 * Apareceram ao traduzir para francês e espanhol: os revisores notaram que a
 * concordância não fechava — e o defeito estava no português original, não na
 * tradução. Estes testes prendem-nos nos quatro idiomas.
 */
class PluraisEConcordanciaTest extends TestCase
{
    public static function idiomas(): array
    {
        return [['pt-pt'], ['en'], ['fr'], ['es']];
    }

    /**
     * @dataProvider idiomas
     */
    public function test_um_passo_nao_e_passos(string $idioma): void
    {
        // Dizia "Faltam 1 passos". E o verbo também concorda em português, por
        // isso não bastava tirar o "s" de passos.
        $um = trans_choice('notifications.incompleteProfile.default.title', 1, ['steps' => 1], $idioma);
        $tres = trans_choice('notifications.incompleteProfile.default.title', 3, ['steps' => 3], $idioma);

        $this->assertNotSame($um, $tres, "[$idioma] singular e plural saem iguais");
        $this->assertStringNotContainsString('|', $um, "[$idioma] a barra do plural foi parar ao ecrã");
        $this->assertStringNotContainsString('|', $tres);
    }

    /**
     * @dataProvider idiomas
     */
    public function test_um_pedido_nao_sao_pedidos(string $idioma): void
    {
        // Esta tinha TRÊS concordâncias: o número, o verbo, e o pronome que
        // retoma os pedidos. Com um só pedido saía "houve 1 pedidos ... para
        // os poderes aceitar".
        $um = trans_choice('notifications.incompleteProfile.with_requests.description', 1, ['requests' => 1], $idioma);
        $quatro = trans_choice('notifications.incompleteProfile.with_requests.description', 4, ['requests' => 4], $idioma);

        $this->assertNotSame($um, $quatro, "[$idioma] singular e plural saem iguais");
        $this->assertStringNotContainsString('|', $um);
        $this->assertStringNotContainsString('{1}', $um, "[$idioma] o intervalo ficou no texto");
        $this->assertStringNotContainsString('[2,*]', $quatro);
    }

    /**
     * @dataProvider idiomas
     */
    public function test_a_descricao_sem_plural_continua_inteira(string $idioma): void
    {
        // O mesmo código chama trans_choice nesta, que não tem formas de
        // plural. Se o Laravel a partisse, o técnico recebia meia frase.
        $texto = trans_choice('notifications.incompleteProfile.default.description', 0, [], $idioma);

        $this->assertNotSame('', $texto);
        $this->assertStringNotContainsString('|', $texto);
    }

    /**
     * @dataProvider idiomas
     */
    public function test_o_titulo_do_documento_nao_assume_o_genero(string $idioma): void
    {
        // Era "{nome} validado", colado no código. Um nome feminino dava
        // "Declaração de Início de Atividade validado".
        foreach (['accept', 'deny'] as $desfecho) {
            $titulo = __("notifications.documents.$desfecho.title", ['type' => 'Declaração de Início de Atividade'], $idioma);

            $this->assertStringContainsString('Declaração de Início de Atividade', $titulo, "[$idioma/$desfecho] o tipo desapareceu do título");
            $this->assertStringNotContainsString(':type', $titulo, "[$idioma/$desfecho] o marcador não foi substituído");
            // O nome fica DEPOIS do particípio: é isso que o desobriga de concordar.
            $this->assertStringEndsWith('Declaração de Início de Atividade', $titulo, "[$idioma/$desfecho] o nome voltou para antes do particípio");
        }
    }
}
