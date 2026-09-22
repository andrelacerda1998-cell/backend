<?php

namespace Tests\Feature\Endpoints;

use App\Models\User;
use Database\Seeders\GenderSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Trocar o email da própria conta.
 *
 * O email só era definido no registo e não havia forma de o corrigir em lado
 * nenhum da app. Quem se enganasse a escrevê-lo ficava a pedir o link de
 * confirmação para um endereço que não é o dele — e sem email confirmado não
 * fica apto, logo não recebe um único pedido.
 */
class TrocarEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();

        /*
         * O endpoint tem `throttle:6,1` porque manda um email a cada pedido.
         * Isso e parte do contrato, mas o limitador conta por IP/utilizador e
         * nao por teste: a partir do setimo pedido da classe, testes que nada
         * tem a ver com limites comecavam a receber 429 e a falhar por ordem
         * de execucao. Fica desligado aqui e fixado no seu proprio teste.
         */
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_o_utilizador_troca_o_proprio_email(): void
    {
        $user = User::factory()->create(['email' => 'errado@exemplo.pt']);

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/email', ['email' => 'certo@exemplo.pt'])
            ->assertOk();

        $this->assertSame('certo@exemplo.pt', $user->refresh()->email);
    }

    public function test_trocar_o_email_anula_a_confirmacao_e_manda_outra(): void
    {
        $user = User::factory()->create([
            'email' => 'antigo@exemplo.pt',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/email', ['email' => 'novo@exemplo.pt'])
            ->assertOk();

        // O que estava confirmado era o endereço ANTIGO. Herdar a confirmação
        // dava por provado o controlo de um endereço que ninguém provou.
        $this->assertNull($user->refresh()->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_pedir_o_mesmo_email_outra_vez_reenvia_a_confirmacao(): void
    {
        // É o caso de quem não recebeu o link. Não se mexe no estado.
        $user = User::factory()->create(['email' => 'eu@exemplo.pt', 'email_verified_at' => null]);

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/email', ['email' => 'eu@exemplo.pt'])
            ->assertOk();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_pedir_o_mesmo_email_ja_confirmado_e_conflito(): void
    {
        $user = User::factory()->create(['email' => 'eu@exemplo.pt', 'email_verified_at' => now()]);

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/email', ['email' => 'eu@exemplo.pt'])
            ->assertStatus(409);

        $this->assertNotNull($user->refresh()->email_verified_at, 'nao se perde uma confirmacao por engano');
        Notification::assertNothingSent();
    }

    public function test_nao_se_rouba_o_email_de_outra_conta(): void
    {
        User::factory()->create(['email' => 'ocupado@exemplo.pt']);
        $user = User::factory()->create(['email' => 'meu@exemplo.pt']);

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/email', ['email' => 'ocupado@exemplo.pt'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertSame('meu@exemplo.pt', $user->refresh()->email);
    }

    public function test_o_email_e_normalizado_antes_de_ser_comparado(): void
    {
        User::factory()->create(['email' => 'ocupado@exemplo.pt']);
        $user = User::factory()->create(['email' => 'meu@exemplo.pt']);

        // Sem normalizar, "  OCUPADO@Exemplo.PT  " passava a unicidade e
        // criava duas contas com o mesmo email a olho humano.
        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/email', ['email' => '  OCUPADO@Exemplo.PT  '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_um_email_mal_formado_e_recusado(): void
    {
        $user = User::factory()->create(['email' => 'meu@exemplo.pt']);

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/email', ['email' => 'nao-e-um-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertSame('meu@exemplo.pt', $user->refresh()->email);
    }

    public function test_nao_se_pode_assumir_um_email_de_importacao(): void
    {
        $user = User::factory()->create(['email' => 'meu@exemplo.pt']);

        // `imp.<id>@piquetapp.pt` sao contas de importacao — a mesma guarda
        // que o registo ja tinha.
        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/email', ['email' => 'imp.42@piquetapp.pt'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_o_email_e_obrigatorio(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->putJson('/api/v1/auth/email', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_o_endpoint_tem_limite_de_pedidos(): void
    {
        // Cada pedido manda um email. Sem tecto, a conta de alguem torna-se um
        // aparelho de enviar mail para enderecos a escolha de quem pede.
        $this->withMiddleware(ThrottleRequests::class);
        $user = User::factory()->create(['email' => 'eu@exemplo.pt']);

        $ultimo = null;
        for ($i = 1; $i <= 8; $i++) {
            $ultimo = $this->actingAs($user, 'api')
                ->putJson('/api/v1/auth/email', ['email' => "n{$i}@exemplo.pt"]);
        }

        $this->assertSame(429, $ultimo->status(), 'o limitador tem de entrar antes do oitavo pedido');
    }

    public function test_trocar_o_email_exige_autenticacao(): void
    {
        $this->putJson('/api/v1/auth/email', ['email' => 'x@exemplo.pt'])->assertStatus(401);
    }

    public function test_ninguem_troca_o_email_de_outra_pessoa(): void
    {
        $outro = User::factory()->create(['email' => 'outro@exemplo.pt']);
        $user = User::factory()->create(['email' => 'meu@exemplo.pt']);

        // O endpoint nunca aceita um id: opera sempre sobre quem esta
        // autenticado. O teste existe para isso continuar verdade.
        $this->actingAs($user, 'api')
            ->putJson('/api/v1/auth/email', ['email' => 'novo@exemplo.pt', 'user_id' => $outro->id])
            ->assertOk();

        $this->assertSame('novo@exemplo.pt', $user->refresh()->email);
        $this->assertSame('outro@exemplo.pt', $outro->refresh()->email);
    }
}
