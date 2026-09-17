<?php

namespace Tests\Feature\Notifications;

use App\Models\Device;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Notifications\CampaignNotification;
use App\Services\Translation\DeeplTranslator;
use App\Services\Translation\NullTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Expo\ExpoPushToken;
use Tests\TestCase;

/**
 * O ingles e um RASCUNHO ate alguem o confirmar.
 *
 * A traducao automatica preenche o campo, mas nao autoriza o envio. Enquanto
 * ninguem a confirmar, quem tem o telemovel em ingles recebe o portugues — que
 * e exatamente o que recebia antes de haver ingles nenhum.
 *
 * Sem isto, "rascunho" era uma etiqueta no backoffice e a traducao por rever
 * saia a toda a gente na mesma. E o que estes testes prendem.
 */
class TraducaoRascunhoTest extends TestCase
{
    use RefreshDatabase;

    private function campanha(array $atributos = []): NotificationCampaign
    {
        return NotificationCampaign::create([
            'name' => 'Campanha',
            'title' => ['pt-pt' => 'Ola', 'en' => 'Hello'],
            'body' => ['pt-pt' => 'Corpo em portugues', 'en' => 'English body'],
            'target_type' => 'both',
            'frequency_type' => 'once',
            'is_active' => true,
            ...$atributos,
        ]);
    }

    private function utilizadorIngles(): User
    {
        $user = User::factory()->create(['language' => 'en']);

        Device::create([
            'user_id' => $user->id,
            'device_name' => 'iPhone',
            'expo_token' => ExpoPushToken::make('ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]'),
        ]);

        return $user;
    }

    public function test_um_ingles_por_rever_nao_sai_a_quem_tem_o_telemovel_em_ingles(): void
    {
        $campanha = $this->campanha(['english_reviewed_at' => null]);

        $mensagem = (new CampaignNotification($campanha))->toExpo($this->utilizadorIngles())->toArray();

        $this->assertSame('Ola', $mensagem['title']);
        $this->assertSame('Corpo em portugues', $mensagem['body']);
    }

    public function test_depois_de_revisto_o_ingles_sai(): void
    {
        $campanha = $this->campanha(['english_reviewed_at' => now()]);

        $mensagem = (new CampaignNotification($campanha))->toExpo($this->utilizadorIngles())->toArray();

        $this->assertSame('Hello', $mensagem['title']);
        $this->assertSame('English body', $mensagem['body']);
    }

    /**
     * Mexer no portugues invalida a revisao: o que estava aprovado ja nao e
     * este texto, e um "revisto" que nao acompanha o que foi revisto da
     * confianca sem a merecer.
     */
    public function test_editar_o_portugues_volta_a_por_a_traducao_em_rascunho(): void
    {
        $campanha = $this->campanha(['english_reviewed_at' => now()]);
        $this->assertFalse($campanha->englishIsDraft());

        $campanha->update(['title' => ['pt-pt' => 'Ola outra vez', 'en' => 'Hello']]);

        $this->assertNull($campanha->refresh()->english_reviewed_at);
        $this->assertTrue($campanha->englishIsDraft());
    }

    /** Uma campanha so em portugues nao e um rascunho — e uma campanha. */
    public function test_sem_ingles_nao_ha_rascunho_nenhum(): void
    {
        $campanha = $this->campanha(['title' => ['pt-pt' => 'Ola'], 'body' => ['pt-pt' => 'Corpo']]);

        $this->assertFalse($campanha->englishIsDraft());

        $mensagem = (new CampaignNotification($campanha))->toExpo($this->utilizadorIngles())->toArray();

        $this->assertSame('Ola', $mensagem['title']);
    }

    public function test_o_tradutor_devolve_o_texto_traduzido(): void
    {
        Http::fake(['*/v2/translate' => Http::response([
            'translations' => [['text' => 'Your technician is on the way']],
        ])]);

        $traduzido = (new DeeplTranslator('chave-de-teste', 'https://api-free.deepl.com'))
            ->translate('O teu tecnico esta a caminho', 'pt-pt', 'en');

        $this->assertSame('Your technician is on the way', $traduzido);
    }

    /**
     * Uma falha do fornecedor deixa o campo por preencher e nao rebenta o
     * formulario: isto e uma conveniencia, nao uma operacao que o utilizador
     * pediu.
     */
    public function test_uma_falha_do_fornecedor_devolve_null_em_vez_de_rebentar(): void
    {
        Http::fake(['*/v2/translate' => Http::response([], 500)]);

        $traduzido = (new DeeplTranslator('chave-de-teste', 'https://api-free.deepl.com'))
            ->translate('Ola', 'pt-pt', 'en');

        $this->assertNull($traduzido);
    }

    public function test_sem_fornecedor_configurado_nao_traduz_e_diz_porque(): void
    {
        $tradutor = new NullTranslator;

        $this->assertFalse($tradutor->isConfigured());
        $this->assertNull($tradutor->translate('Ola', 'pt-pt', 'en'));
    }
}
