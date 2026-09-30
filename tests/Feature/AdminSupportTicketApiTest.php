<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os tickets dos tecnicos, vistos do backoffice.
 *
 * RefreshDatabase (transacao) e nao DatabaseTruncation: truncar CONFIRMA o que
 * o teste escreve, e isso atravessa a fronteira da classe -- foi assim que o
 * AvaliacoesVoltamNoDeployTest apanhou um Vendor que nao era dele e parou um
 * deploy.
 */
class AdminSupportTicketApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Criar um Vendor dispara observers que tentam indexar no Meilisearch.
        config(['scout.driver' => 'null']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function tecnico(string $nome = 'Rui'): Vendor
    {
        $user = User::factory()->create(['first_name' => $nome, 'last_name' => 'Silva']);

        return Vendor::create(['user_id' => $user->id, 'username' => 'u'.$user->id]);
    }

    public function test_it_lists_tickets_with_who_wrote_them(): void
    {
        $vendor = $this->tecnico('Danubia');
        $vendor->supportTickets()->create([
            'subject' => 'Validacao da conta',
            'message' => 'Ainda nao recebi confirmacao.',
            'status' => 'open',
        ]);

        $res = $this->withAuth()->getJson('/api/v1/admin/support-tickets')->assertOk();

        $res->assertJsonPath('data.items.0.subject', 'Validacao da conta')
            ->assertJsonPath('data.items.0.status', 'open')
            // Sem o nome e o telefone, o backoffice tinha um texto sem dono --
            // e a primeira coisa que se faz a um tecnico parado e ligar-lhe.
            ->assertJsonPath('data.items.0.vendor.id', $vendor->id)
            ->assertJsonPath('data.items.0.vendor.name', 'Danubia Silva');
        $this->assertNotNull($res->json('data.items.0.vendor.phone_number'));
    }

    public function test_open_tickets_come_first_and_oldest_within_each_group(): void
    {
        $vendor = $this->tecnico();

        $respondidoAntigo = $vendor->supportTickets()->create(['subject' => 'respondido', 'message' => 'x', 'status' => 'answered']);
        $respondidoAntigo->forceFill(['created_at' => now()->subDays(30)])->save();

        $abertoRecente = $vendor->supportTickets()->create(['subject' => 'aberto recente', 'message' => 'x', 'status' => 'open']);
        $abertoRecente->forceFill(['created_at' => now()->subDay()])->save();

        $abertoAntigo = $vendor->supportTickets()->create(['subject' => 'aberto antigo', 'message' => 'x', 'status' => 'open']);
        $abertoAntigo->forceFill(['created_at' => now()->subDays(10)])->save();

        $ids = collect($this->withAuth()->getJson('/api/v1/admin/support-tickets')->json('data.items'))
            ->pluck('subject')->all();

        // Abertos primeiro (pedem alguma coisa a alguem) e, dentro deles, quem
        // espera ha mais tempo no topo.
        $this->assertSame(['aberto antigo', 'aberto recente', 'respondido'], $ids);
    }

    public function test_it_marks_a_no_show_dispute_and_the_service_it_is_about(): void
    {
        $vendor = $this->tecnico();
        $vendor->supportTickets()->create([
            'subject' => 'Contestação de falta — serviço #4821',
            'message' => 'O cliente nao estava em casa.',
            'status' => 'open',
        ]);
        $vendor->supportTickets()->create(['subject' => 'Duvida', 'message' => 'x', 'status' => 'open']);

        $itens = collect($this->withAuth()->getJson('/api/v1/admin/support-tickets')->json('data.items'))
            ->keyBy('subject');

        // Quem contesta uma falta foi cobrado em metade do que ia receber: o
        // backoffice precisa de o distinguir de uma duvida qualquer.
        $this->assertTrue($itens['Contestação de falta — serviço #4821']['is_no_show_dispute']);
        $this->assertSame(4821, $itens['Contestação de falta — serviço #4821']['disputed_service_id']);
        $this->assertFalse($itens['Duvida']['is_no_show_dispute']);
        $this->assertNull($itens['Duvida']['disputed_service_id']);
    }

    public function test_replying_marks_it_answered_and_stamps_the_time(): void
    {
        $t = $this->tecnico()->supportTickets()->create(['subject' => 's', 'message' => 'm', 'status' => 'open']);

        $this->withAuth()
            ->putJson("/api/v1/admin/support-tickets/{$t->id}", ['admin_reply' => 'Ja esta tratado.'])
            ->assertOk()
            ->assertJsonPath('data.admin_reply', 'Ja esta tratado.')
            // Sem isto, um ticket respondido continuava a contar como aberto.
            ->assertJsonPath('data.status', 'answered');

        $this->assertNotNull($t->fresh()->replied_at);
    }

    public function test_an_explicit_status_wins_over_the_automatic_one(): void
    {
        $t = $this->tecnico()->supportTickets()->create(['subject' => 's', 'message' => 'm', 'status' => 'open']);

        $this->withAuth()
            ->putJson("/api/v1/admin/support-tickets/{$t->id}", ['admin_reply' => 'Resolvido.', 'status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_changing_only_the_status_does_not_stamp_a_reply_time(): void
    {
        $t = $this->tecnico()->supportTickets()->create(['subject' => 's', 'message' => 'm', 'status' => 'open']);

        $this->withAuth()
            ->putJson("/api/v1/admin/support-tickets/{$t->id}", ['status' => 'closed'])
            ->assertOk();

        // Fechar sem responder nao e responder: `replied_at` diria que alguem
        // escreveu qualquer coisa ao tecnico, e ninguem escreveu.
        $this->assertNull($t->fresh()->replied_at);
    }

    public function test_it_rejects_an_unknown_status(): void
    {
        $t = $this->tecnico()->supportTickets()->create(['subject' => 's', 'message' => 'm', 'status' => 'open']);

        $this->withAuth()
            ->putJson("/api/v1/admin/support-tickets/{$t->id}", ['status' => 'arquivado'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_it_filters_by_status_and_searches_the_text(): void
    {
        $v = $this->tecnico();
        $v->supportTickets()->create(['subject' => 'IBAN em falta', 'message' => 'nao consigo', 'status' => 'open']);
        $v->supportTickets()->create(['subject' => 'Outra coisa', 'message' => 'x', 'status' => 'closed']);

        $this->withAuth()->getJson('/api/v1/admin/support-tickets?status=open')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.subject', 'IBAN em falta');

        $this->withAuth()->getJson('/api/v1/admin/support-tickets?search=IBAN')
            ->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_it_needs_the_admin_token(): void
    {
        // Com token configurado mas sem o mandar: 401.
        config(['services.admin_api.token' => 'a-valid-token']);
        $this->getJson('/api/v1/admin/support-tickets')->assertStatus(401);

        // Sem token NENHUM configurado, a API de admin esta desligada e
        // responde 503 -- fail-closed, nunca aberta por omissao.
        config(['services.admin_api.token' => null]);
        $this->getJson('/api/v1/admin/support-tickets')->assertStatus(503);
    }
}
