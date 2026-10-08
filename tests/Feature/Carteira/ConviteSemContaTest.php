<?php

namespace Tests\Feature\Carteira;

use App\Enums\Services\ServiceStatus;
use App\Models\Referral\Referral;
use App\Models\Service;
use App\Models\User;
use App\Services\Carteira\CarteiraDoCliente;
use App\Services\Carteira\Convites;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Geocoder\Facades\Geocoder;
use Tests\TestCase;

/**
 * Quem pede sem conta também pode usar um código de convite.
 *
 * No checkout sem sessão, o código só se VERIFICA (POST /common/referral/check).
 * Aplica-se no guest/register, quando a conta passa a existir ao confirmar o
 * telemóvel — e o checkout, já com sessão, recalcula com os 5 € na Carteira.
 * Antes disto, o campo do cupão respondia "inválido" a quem não tinha conta:
 * precisamente os amigos novos que o programa quer trazer.
 */
class ConviteSemContaTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFONE = '+351913000111';

    private Convites $convites;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
        Geocoder::shouldReceive('setLanguage')->andReturnSelf();
        Geocoder::shouldReceive('getAddressForCoordinates')->andReturn([]);
        config(['scout.driver' => 'null']);
        $this->convites = app(Convites::class);
    }

    private function quemConvida(): User
    {
        $ana = User::factory()->create(['first_name' => 'Ana', 'phone_number' => '+351913000999']);
        Service::factory()->create(['customer_id' => $ana->id, 'status' => ServiceStatus::CLOSED]);

        return $ana;
    }

    private function registar(?string $codigo)
    {
        Cache::put('guest_phone_verified:'.self::TELEFONE, 'token-teste', 600);

        return $this->withHeader('Accept-Language', 'pt-PT')->postJson('/api/v1/auth/guest/register', array_filter([
            'phone_number' => self::TELEFONE,
            'verification_token' => 'token-teste',
            'referral_code' => $codigo,
            'address' => [
                'latitude' => 38.7104, 'longitude' => -9.1366,
                'street_name' => 'Rua Augusta', 'street_number' => '100',
                'postal_code' => '1100-048', 'city' => 'Lisboa', 'state' => 'Lisboa', 'country' => 'Portugal',
            ],
        ]));
    }

    // ----------------------------------------------------------- verificar

    public function test_sem_conta_o_codigo_verifica_se_mas_nao_se_aplica(): void
    {
        $codigo = $this->convites->codigoDe($this->quemConvida())->code;

        $this->withHeader('Accept-Language', 'pt-PT')
            ->postJson('/api/v1/common/referral/check', ['code' => strtolower($codigo), 'phone_number' => self::TELEFONE])
            ->assertOk()
            ->assertJsonPath('data.credit', 500)
            ->assertJsonPath('data.code', $codigo);

        $this->assertSame(0, Referral::count(), 'só verifica; nada se cria sem conta');
    }

    public function test_sem_conta_um_codigo_que_nao_existe_e_recusado(): void
    {
        $this->postJson('/api/v1/common/referral/check', ['code' => 'XXXXXX'])->assertNotFound();
    }

    public function test_sem_conta_um_telemovel_que_ja_pagou_e_recusado_logo(): void
    {
        $codigo = $this->convites->codigoDe($this->quemConvida())->code;
        $antigo = User::factory()->create(['phone_number' => self::TELEFONE]);
        Service::factory()->create(['customer_id' => $antigo->id, 'status' => ServiceStatus::CLOSED]);

        $this->withHeader('Accept-Language', 'pt-PT')
            ->postJson('/api/v1/common/referral/check', ['code' => $codigo, 'phone_number' => self::TELEFONE])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Os códigos de convite são para quem ainda não fez nenhum serviço na Piquet.');
    }

    // ------------------------------------------------------------ aplicar

    public function test_ao_criar_a_conta_o_codigo_aplica_se_e_os_5_euros_entram_na_carteira(): void
    {
        $ana = $this->quemConvida();
        $codigo = $this->convites->codigoDe($ana)->code;

        $this->registar($codigo)
            ->assertOk()
            ->assertJsonPath('data.referral.applied', true);

        $novo = User::where('phone_number', self::TELEFONE)->sole();
        $this->assertSame($ana->id, Referral::where('referred_user_id', $novo->id)->value('referrer_user_id'));
        $this->assertSame(500, app(CarteiraDoCliente::class)->convitesDisponivel($novo));
    }

    public function test_se_o_codigo_ja_nao_servir_a_conta_cria_se_na_mesma_e_diz_porque(): void
    {
        $this->registar('XXXXXX')
            ->assertOk()
            ->assertJsonPath('data.referral.applied', false)
            ->assertJsonPath('data.referral.message', 'Este código de convite não existe.');

        $this->assertSame(1, User::where('phone_number', self::TELEFONE)->count());
    }

    public function test_sem_codigo_o_registo_e_como_era(): void
    {
        $r = $this->registar(null)->assertOk();

        $this->assertNull($r->json('data.referral'));
        $this->assertSame(0, Referral::count());
    }
}
