<?php

namespace Tests\Feature\Carteira;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use App\Services\Carteira\Convites;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O link da mensagem de convite (/c/{codigo}) leva à loja certa com o código.
 */
class ConviteLinkTest extends TestCase
{
    use RefreshDatabase;

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';

    private string $codigo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        config(['scout.driver' => 'null']);

        $ana = User::factory()->create(['first_name' => 'Ana']);
        Service::factory()->create(['customer_id' => $ana->id, 'status' => ServiceStatus::CLOSED]);
        $this->codigo = app(Convites::class)->codigoDe($ana)->code;
    }

    public function test_no_android_vai_direto_ao_google_play_com_o_codigo_no_referrer(): void
    {
        $r = $this->withHeader('User-Agent', self::ANDROID)->get('/c/'.strtolower($this->codigo));

        $r->assertRedirect();
        $destino = $r->headers->get('Location');
        $this->assertStringStartsWith('https://play.google.com/store/apps/details?id=com.piquetapp.customer', $destino);
        parse_str(parse_url($destino, PHP_URL_QUERY), $q);
        parse_str($q['referrer'], $referrer);
        $this->assertSame($this->codigo, $referrer['piquet_convite']);
    }

    public function test_no_iphone_mostra_o_codigo_e_o_botao_que_o_copia_e_abre_a_app_store(): void
    {
        $this->withHeader('User-Agent', self::IPHONE)->get('/c/'.$this->codigo)
            ->assertOk()
            ->assertSee($this->codigo)
            ->assertSee('Copiar código e instalar')
            ->assertSee('apps.apple.com/pt/app/piquet/id6745871587', false)
            ->assertSee('piquet.customer://convite/'.$this->codigo, false);
    }

    public function test_no_computador_mostra_as_duas_lojas(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Safari/605')->get('/c/'.$this->codigo)
            ->assertOk()
            ->assertSee('App Store')
            ->assertSee('Google Play');
    }

    public function test_um_codigo_que_nao_existe_segue_para_a_loja_sem_codigo(): void
    {
        $r = $this->withHeader('User-Agent', self::ANDROID)->get('/c/NAOHA99');

        $this->assertStringNotContainsString('referrer', $r->headers->get('Location'));
        $this->withHeader('User-Agent', self::IPHONE)->get('/c/NAOHA99')->assertOk()->assertDontSee('NAOHA99');
    }

    public function test_o_resumo_do_convite_traz_o_link(): void
    {
        $ana = User::where('first_name', 'Ana')->sole();

        $this->actingAs($ana, 'api')->getJson('/api/v1/customer/referral')
            ->assertOk()
            ->assertJsonPath('data.share_url', url('/c/'.$this->codigo));
    }
}
