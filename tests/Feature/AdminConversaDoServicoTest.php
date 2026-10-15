<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\Gender;
use App\Models\Message;
use App\Models\Service;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A conversa cliente-técnico no backoffice: só para resolver uma reclamação ou
 * uma disputa, com caso, motivo e quem leu (ConversaDoServicoController).
 */
class AdminConversaDoServicoTest extends TestCase
{
    use RefreshDatabase;

    private string $chavePublica;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'null', 'services.admin_api.token' => 'a-valid-token']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
    }

    /** Um serviço com a chave da conversa, como o PublicKeyController a cria. */
    private function servico(array $atributos = []): Service
    {
        $par = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($par, $privada);
        $this->chavePublica = openssl_pkey_get_details($par)['key'];

        $vendor = Vendor::factory()->create();

        return Service::factory()->create(['vendor_id' => $vendor->id, 'rsa' => $privada, ...$atributos]);
    }

    /** Uma mensagem como a app a envia: cifrada com a chave pública do serviço. */
    private function mensagem(Service $servico, int $de, string $texto, bool $lida = false): Message
    {
        openssl_public_encrypt($texto, $cifrada, $this->chavePublica);

        return $servico->messages()->create([
            'user_id' => $de,
            'message' => base64_encode($cifrada),
            'is_read' => $lida,
        ]);
    }

    private function admin(?string $staff = 'ana@piquet.pt'): static
    {
        // Os cabeçalhos ficam de uma chamada para a seguinte: sem isto, o
        // "sem saber quem lê" levava o email da chamada anterior.
        $this->flushHeaders();

        return $this->withHeaders(array_filter([
            'Authorization' => 'Bearer a-valid-token',
            'X-Staff-Email' => $staff,
        ]));
    }

    private function ler(Service $servico, string $caso, string $motivo = 'O cliente diz que o técnico combinou outro preço no chat.', ?string $staff = 'ana@piquet.pt')
    {
        return $this->admin($staff)->postJson('/api/v1/admin/services/'.$servico->id.'/conversa', [
            'caso' => $caso,
            'motivo' => $motivo,
        ]);
    }

    public function test_os_casos_dizem_o_que_ha_sem_mostrar_a_conversa(): void
    {
        $servico = $this->servico([
            'problem_reported_at' => now()->subHour(),
            'problem_reported_by' => 'customer',
            'problem_reason' => 'price',
        ]);
        $this->mensagem($servico, $servico->customer_id, 'Olá');

        $this->admin()->getJson('/api/v1/admin/services/'.$servico->id.'/conversa/casos')
            ->assertOk()
            ->assertJsonPath('data.problema.por', 'customer')
            ->assertJsonPath('data.problema.motivo', 'price')
            ->assertJsonPath('data.falta', null)
            ->assertJsonPath('data.mensagens', 1)
            ->assertJsonMissingPath('data.mensagens.0.texto');
    }

    /** Com um problema reportado, lê-se a conversa toda, decifrada e por ordem. */
    public function test_com_um_problema_reportado_le_a_conversa_por_ordem(): void
    {
        $servico = $this->servico(['problem_reported_at' => now()]);
        $tecnico = $servico->vendor->user_id;
        $this->mensagem($servico, $servico->customer_id, 'Consegue vir às 15h?');
        $this->mensagem($servico, $tecnico, 'Sim, mas a peça fica por 80 €.');

        $resposta = $this->ler($servico, 'problema')->assertOk();

        $this->assertSame(['cliente', 'tecnico'], array_column($resposta->json('data.mensagens'), 'de'));
        $this->assertSame(
            ['Consegue vir às 15h?', 'Sim, mas a peça fica por 80 €.'],
            array_column($resposta->json('data.mensagens'), 'texto'),
        );
    }

    /** O "lido" é do cliente e do técnico: a equipa a ler não o muda. */
    public function test_ler_nao_marca_nada_como_lido(): void
    {
        $servico = $this->servico(['vendor_no_show_at' => now()]);
        $mensagem = $this->mensagem($servico, $servico->customer_id, 'Onde está?');

        $this->ler($servico, 'falta')->assertOk()->assertJsonPath('data.mensagens.0.lida', false);

        $this->assertFalse((bool) $mensagem->fresh()->is_read);
    }

    /** Sem o caso no pedido, não se lê: não basta dizer que há um problema. */
    public function test_sem_o_caso_no_pedido_recusa(): void
    {
        $servico = $this->servico();
        $this->mensagem($servico, $servico->customer_id, 'Privado');

        $this->ler($servico, 'problema')->assertForbidden()->assertJsonMissingPath('data.mensagens');
        $this->ler($servico, 'falta')->assertForbidden();
    }

    /** O ticket vive no Supabase: é o backoffice que confirma que existe e está aberto. */
    public function test_com_um_ticket_le(): void
    {
        $servico = $this->servico();
        $this->mensagem($servico, $servico->customer_id, 'Olá');

        $this->ler($servico, 'ticket:TK-1234')->assertOk()->assertJsonCount(1, 'data.mensagens');
        $this->ler($servico, 'ticket:qualquer-coisa')->assertUnprocessable();
    }

    public function test_sem_motivo_ou_sem_saber_quem_le_recusa(): void
    {
        $servico = $this->servico(['problem_reported_at' => now()]);

        $this->ler($servico, 'problema', 'curto')->assertUnprocessable();
        $this->ler($servico, 'problema', staff: null)->assertUnprocessable();
    }

    public function test_sem_o_token_do_backoffice_nao_responde(): void
    {
        $servico = $this->servico(['problem_reported_at' => now()]);

        $this->postJson('/api/v1/admin/services/'.$servico->id.'/conversa', ['caso' => 'problema', 'motivo' => 'um motivo comprido q.b.'])
            ->assertUnauthorized();
    }

    /** Sem a chave do serviço não há texto, mas a conversa não rebenta. */
    public function test_sem_a_chave_do_servico_as_mensagens_vem_sem_texto(): void
    {
        $servico = $this->servico(['problem_reported_at' => now()]);
        $this->mensagem($servico, $servico->customer_id, 'Não se vai ler');
        $servico->forceFill(['rsa' => null])->save();

        $this->ler($servico, 'problema')->assertOk()->assertJsonPath('data.mensagens.0.texto', null);
    }
}
