<?php

namespace Tests\Feature\Api\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingConsentTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(): User
    {
        return User::factory()->create(['marketing_consent_at' => null]);
    }

    public function test_aceitar_guarda_a_data_do_consentimento(): void
    {
        $user = $this->utilizador();

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/profile/marketing-consent', ['accepted' => true])
            ->assertOk();

        // A data importa tanto como o sim: sem ela nao se demonstra QUANDO foi
        // dado, que e o que o RGPD exige.
        $this->assertNotNull($user->fresh()->marketing_consent_at);
    }

    public function test_recusar_limpa_o_consentimento(): void
    {
        $user = $this->utilizador();
        $user->marketing_consent_at = now()->subDay();
        $user->save();

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/profile/marketing-consent', ['accepted' => false])
            ->assertOk();

        $this->assertNull($user->fresh()->marketing_consent_at);
    }

    public function test_o_me_devolve_o_estado_do_consentimento(): void
    {
        $user = $this->utilizador();

        $semConsentimento = $this->actingAs($user, 'api')->getJson('/api/v1/auth/me');
        $semConsentimento->assertOk()->assertJsonPath('data.marketing_consent_at', null);

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/profile/marketing-consent', ['accepted' => true]);

        $comConsentimento = $this->actingAs($user->fresh(), 'api')->getJson('/api/v1/auth/me');
        $this->assertNotNull($comConsentimento->json('data.marketing_consent_at'));
    }

    public function test_sem_sessao_nao_se_mexe_no_consentimento(): void
    {
        $this->putJson('/api/v1/auth/profile/marketing-consent', ['accepted' => true])
            ->assertUnauthorized();
    }

    public function test_quem_se_regista_fica_com_o_consentimento_ligado(): void
    {
        $this->postJson('/api/v1/auth/registration/customer', [
            'name' => 'Maria Teste',
            'email' => 'maria.'.uniqid().'@exemplo.pt',
            'phone_number' => '+351912000111',
            'password' => 'PiquetTeste!2026',
            'password_confirmation' => 'PiquetTeste!2026',
        ])->assertSuccessful();

        // Decisao do negocio: ligado de origem. Guarda-se a data na mesma, para
        // se saber desde quando se pode enviar.
        $this->assertNotNull(User::latest('id')->first()->marketing_consent_at);
    }

    public function test_o_campo_accepted_e_obrigatorio(): void
    {
        $this->actingAs($this->utilizador(), 'api')
            ->putJson('/api/v1/auth/profile/marketing-consent', [])
            ->assertStatus(422);
    }
}
