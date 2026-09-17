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
 * Cada um recebe no SEU idioma — e nada sai por rever.
 *
 * Sao duas regras, e o sitio onde cada uma vive e que as torna compativeis:
 *
 *   - o IDIOMA decide-se por utilizador, sem excepcoes: app em portugues,
 *     push em portugues; app em ingles, push em ingles;
 *   - a traducao por rever trava a CAMPANHA inteira, nao o idioma de
 *     ninguem.
 *
 * A alternativa — mandar portugues a quem tem o telemovel em ingles enquanto
 * ninguem revia — escondia o problema no unico sitio onde ja nao tem conserto:
 * no telemovel de quem o recebe. Assim, ou sai bem para todos, ou nao sai.
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

    private function utilizador(string $idioma): User
    {
        $user = User::factory()->create(['language' => $idioma]);

        Device::create([
            'user_id' => $user->id,
            'device_name' => 'iPhone',
            'expo_token' => ExpoPushToken::make('ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]'),
        ]);

        return $user;
    }

    public function test_quem_tem_a_app_em_portugues_recebe_portugues(): void
    {
        $campanha = $this->campanha(['english_reviewed_at' => now()]);

        $mensagem = (new CampaignNotification($campanha))->toExpo($this->utilizador('pt-pt'))->toArray();

        $this->assertSame('Ola', $mensagem['title']);
        $this->assertSame('Corpo em portugues', $mensagem['body']);
    }

    public function test_quem_tem_a_app_em_ingles_recebe_ingles(): void
    {
        $campanha = $this->campanha(['english_reviewed_at' => now()]);

        $mensagem = (new CampaignNotification($campanha))->toExpo($this->utilizador('en'))->toArray();

        $this->assertSame('Hello', $mensagem['title']);
        $this->assertSame('English body', $mensagem['body']);
    }

    /**
     * O rascunho nao muda o idioma de ninguem: trava a campanha toda. Quem tem
     * a app em ingles nao passa a receber portugues — nao recebe nada, e nem o
     * portugues sai, porque a campanha inteira fica a espera de revisao.
     */
    public function test_uma_traducao_por_rever_trava_a_campanha_inteira(): void
    {
        $campanha = $this->campanha(['english_reviewed_at' => null]);

        $this->assertTrue($campanha->englishIsDraft());
        $this->assertFalse($campanha->shouldSend());
    }

    public function test_depois_de_revista_a_campanha_sai(): void
    {
        $campanha = $this->campanha(['english_reviewed_at' => now()]);

        $this->assertFalse($campanha->englishIsDraft());
        $this->assertTrue($campanha->shouldSend());
    }

    /** So em portugues nao ha nada por rever: a campanha sai. */
    public function test_uma_campanha_so_em_portugues_sai_sem_revisao_nenhuma(): void
    {
        $campanha = $this->campanha(['title' => ['pt-pt' => 'Ola'], 'body' => ['pt-pt' => 'Corpo']]);

        $this->assertTrue($campanha->shouldSend());
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

        $mensagem = (new CampaignNotification($campanha))->toExpo($this->utilizador('en'))->toArray();

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
