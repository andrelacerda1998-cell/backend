<?php

namespace Tests\Feature\Api\User;

use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que o /auth/refresh promete quando devolve 200.
 *
 * O refresh do tymon reassina as claims do token antigo sem nunca ir à base
 * confirmar que o `sub` ainda resolve para alguém. Isso fazia dele uma fábrica
 * infinita de tokens para contas que já não existem: o refresh devolvia 200,
 * o token novo dava 401 em todo o resto, e o cliente — que só termina a sessão
 * quando o refresh falha — voltava a renovar. Em vez de se resolver, o erro
 * acelerava: 989 pedidos num minuto de um telemóvel parado, até 429.
 *
 * O contrato aqui é simples e é o que estes testes fixam: um 200 do refresh
 * significa que o token devolvido autentica. Se não autentica, é 401.
 */
class RenovacaoDeTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_devolve_token_novo_a_quem_ainda_tem_conta(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $resposta = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/refresh')
            ->assertOk();

        $novo = $resposta->json('data.access_token');
        $this->assertNotEmpty($novo);

        // A promessa: o token que saiu daqui autentica mesmo.
        $this->withHeader('Authorization', "Bearer {$novo}")
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_recusa_renovar_o_token_de_uma_conta_que_ja_nao_existe(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $user->delete();
        $this->esquecerGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(401);
    }

    /**
     * O ciclo em si, não só o seu primeiro passo.
     *
     * Antes da correção este teste passava a primeira asserção (o refresh dava
     * 200) e falhava na segunda: o token novo dava 401, e renová-lo dava 200
     * outra vez, indefinidamente. É essa a espiral que se fecha aqui.
     */
    public function test_a_renovacao_nao_se_repete_indefinidamente_para_uma_conta_apagada(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);
        $user->delete();
        $this->esquecerGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        // E o pedido que o cliente faria a seguir — renovar — também tem de
        // falhar, senão ele tenta para sempre.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(401);
    }

    /**
     * Num teste, o guard vive no mesmo processo que o pedido e guarda o
     * utilizador que já resolveu — inclusive depois de ele ser apagado. Em
     * produção cada pedido arranca limpo; aqui é preciso dizê-lo à mão, senão
     * o teste mede a cache e não o comportamento.
     */
    private function esquecerGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
