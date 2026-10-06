<?php

namespace Tests\Feature\Vendors;

use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O valor/hora fica entre 8 € e 50 € venha por onde vier.
 *
 * A 06/10/2026 havia três técnicos reais a 1 €/h, a aparecer aos clientes
 * como "Mais barato" (uma rotura de cano de 2 h por 4,59 €). Só o ecrã
 * "Valor/hora" da app tinha limites; o registo e o ecrã de pagamentos
 * aceitavam a partir de 1 €, e o servidor não validava nada.
 */
class ValorHoraLimitesTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN_VALIDO = 'PT50000201231234567890154';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    // ------------------------------------------------- ecrã "Valor/hora"

    public function test_no_ecra_do_valor_hora_um_euro_e_recusado_e_nada_muda(): void
    {
        $vendor = Vendor::factory()->create();
        $antes = (int) $vendor->getRawOriginal('price_rate');

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/price-rate', ['rate' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rate']);

        $this->assertSame($antes, (int) $vendor->refresh()->getRawOriginal('price_rate'));
    }

    public function test_os_limites_oito_e_cinquenta_sao_aceites(): void
    {
        $vendor = Vendor::factory()->create();

        foreach ([Vendor::VALOR_HORA_MINIMO, Vendor::VALOR_HORA_MAXIMO] as $valor) {
            $this->actingAs($vendor->user, 'api')
                ->putJson('/api/v1/vendor/settings/price-rate', ['rate' => $valor])
                ->assertOk();

            $this->assertSame($valor * 100, (int) $vendor->refresh()->getRawOriginal('price_rate'));
        }
    }

    public function test_acima_de_cinquenta_e_recusado(): void
    {
        $vendor = Vendor::factory()->create();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/price-rate', ['rate' => 51])
            ->assertStatus(422);
    }

    public function test_a_mensagem_diz_os_limites(): void
    {
        $vendor = Vendor::factory()->create();

        $mensagem = $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/price-rate', ['rate' => 1])
            ->json('errors.rate.0');

        $this->assertStringContainsString('8', $mensagem);
        $this->assertStringContainsString('50', $mensagem);
    }

    // ------------------------------------------------- ecrã "Pagamentos"

    public function test_nos_pagamentos_um_euro_e_recusado(): void
    {
        $vendor = Vendor::factory()->create();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/update/payment', ['iban' => self::IBAN_VALIDO, 'price_rate' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price_rate']);
    }

    public function test_nos_pagamentos_texto_em_vez_de_numero_e_recusado(): void
    {
        $vendor = Vendor::factory()->create();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/update/payment', ['iban' => self::IBAN_VALIDO, 'price_rate' => 'barato'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price_rate']);
    }

    public function test_nos_pagamentos_um_valor_dentro_dos_limites_grava(): void
    {
        $vendor = Vendor::factory()->create();

        $this->actingAs($vendor->user, 'api')
            ->putJson('/api/v1/vendor/settings/update/payment', ['iban' => self::IBAN_VALIDO, 'price_rate' => 18])
            ->assertOk();

        $this->assertSame(1800, (int) $vendor->refresh()->getRawOriginal('price_rate'));
    }

    // ------------------------------------------------------------ registo

    public function test_no_registo_um_euro_e_recusado(): void
    {
        $this->postJson('/api/v1/auth/registration/vendor', [
            'name' => 'Técnico Teste',
            'email' => 'tecnico.limites@piquet.test',
            'phone_number' => '+351912345678',
            'password' => 'Uma-Palavra-Passe-Longa-9',
            'password_confirmation' => 'Uma-Palavra-Passe-Longa-9',
            'price_rate' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['price_rate']);
    }
}
