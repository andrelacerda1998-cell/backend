<?php

namespace Tests\Feature;

use App\Settings\RateSettings;
use Tests\TestCase;

class AdminFeeSettingsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RateSettings::fake() (padrão já usado em tests/Unit/RateServiceTest.php) --
        // evita depender das migrations de database/settings e de RefreshDatabase,
        // e não deixa valores alterados para trás para outros testes.
        RateSettings::fake([
            'daytime' => 100,
            'evening' => 120,
            'night' => 150,
            'late_night' => 190,
            'midnight' => 190,
            'kilometer_price' => 80,
            'system_commission' => 25,
        ]);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    public function test_it_returns_the_current_fee_settings(): void
    {
        $this->withAuth()
            ->getJson('/api/v1/admin/fee-settings')
            ->assertOk()
            ->assertJsonPath('data.system_commission', 25)
            ->assertJsonPath('data.kilometer_price', 0.8)
            ->assertJsonStructure([
                'data' => ['daytime', 'evening', 'night', 'late_night', 'midnight', 'kilometer_price', 'system_commission'],
            ]);
    }

    public function test_it_updates_the_fee_settings_and_persists_them(): void
    {
        $payload = [
            'daytime' => 110,
            'evening' => 130,
            'night' => 160,
            'late_night' => 200,
            'midnight' => 90,
            'kilometer_price' => 0.42,
            'system_commission' => 30,
        ];

        $this->withAuth()
            ->putJson('/api/v1/admin/fee-settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.system_commission', 30)
            ->assertJsonPath('data.kilometer_price', 0.42);

        $rateSettings = app(RateSettings::class);
        $this->assertSame(30, $rateSettings->system_commission);
        // Gravado em cêntimos (42), devolvido em euros (0.42) -- ver present() no controller.
        $this->assertSame(42, $rateSettings->kilometer_price);
    }

    public function test_it_rejects_an_invalid_update(): void
    {
        $this->withAuth()
            ->putJson('/api/v1/admin/fee-settings', ['system_commission' => 150])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['daytime', 'system_commission']);
    }
}
