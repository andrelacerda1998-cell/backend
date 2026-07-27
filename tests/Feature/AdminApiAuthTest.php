<?php

namespace Tests\Feature;

use App\Settings\RateSettings;
use Tests\TestCase;

/**
 * A API v1/admin/* é consumida servidor-a-servidor pelo backoffice Next.js
 * (nunca pelo browser). Aqui testamos só o middleware AdminApiToken -- os
 * endpoints em si têm os seus próprios testes.
 */
class AdminApiAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Só é preciso para o teste "token correto", que chega mesmo ao
        // FeeSettingsController -- os outros são rejeitados antes disso.
        RateSettings::fake(['daytime' => 100, 'evening' => 120, 'night' => 150, 'late_night' => 190, 'midnight' => 190, 'kilometer_price' => 80, 'system_commission' => 25]);
    }

    public function test_request_without_token_is_rejected(): void
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        $this->getJson('/api/v1/admin/fee-settings')
            ->assertUnauthorized();
    }

    public function test_request_with_wrong_token_is_rejected(): void
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        $this->withHeaders(['Authorization' => 'Bearer wrong-token'])
            ->getJson('/api/v1/admin/fee-settings')
            ->assertUnauthorized();
    }

    public function test_request_is_rejected_when_no_token_is_configured(): void
    {
        // Fail-closed: sem ADMIN_API_TOKEN definido em produção, a API de admin
        // fica desligada em vez de aceitar qualquer pedido.
        config(['services.admin_api.token' => null]);

        $this->withHeaders(['Authorization' => 'Bearer anything'])
            ->getJson('/api/v1/admin/fee-settings')
            ->assertStatus(503);
    }

    public function test_request_with_correct_token_is_accepted(): void
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        $this->withHeaders(['Authorization' => 'Bearer a-valid-token'])
            ->getJson('/api/v1/admin/fee-settings')
            ->assertOk();
    }
}
