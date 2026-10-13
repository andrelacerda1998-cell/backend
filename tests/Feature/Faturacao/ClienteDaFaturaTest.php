<?php

namespace Tests\Feature\Faturacao;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\InvoiceXpress\InvoiceVendorService;
use App\Services\InvoiceXpress\SystemInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O cliente que vai em cada fatura (HasInvoicesItems::clienteDaFatura).
 *
 * Visto a 13/10/2026 na conta de testes do InvoiceXpress:
 *  - sem nome, a fatura é recusada ("Elemento client não fornecido");
 *  - sem código, um cliente que já existe é reconhecido pelo NOME e o resto
 *    dos campos é ignorado: dois clientes com o mesmo nome ficavam com o NIF
 *    e a morada do primeiro.
 *
 * O InvoiceXpress está falso (Http::fake): mede-se o que se lhe envia.
 */
class ClienteDaFaturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
        Http::fake(['*' => Http::response(['invoice_receipt' => ['id' => 4242]], 200)]);
    }

    private function cliente(?string $primeiro, ?string $ultimo, array $atributos = []): User
    {
        return User::factory()->create(['first_name' => $primeiro, 'last_name' => $ultimo, ...$atributos]);
    }

    private function servicoDe(User $cliente, ?string $nifDoPedido = null): Service
    {
        $vendor = Vendor::factory()->create(['invoice_workspace' => 'oficina-teste', 'auth_token' => 'chave-de-teste']);

        return Service::factory()->create([
            'customer_id' => $cliente->id,
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::CLOSED,
            'nif' => $nifDoPedido,
        ]);
    }

    /** Os dados de faturação que o cliente preenche na app. */
    private function comDadosDeFaturacao(User $cliente, ?string $nome, ?string $nif): User
    {
        $cliente->billingInfo()->create([
            'name' => $nome, 'nif' => $nif,
            'address' => 'Rua da Empresa 10', 'postal_code' => '4000-001', 'locality' => 'Porto',
        ]);

        return $cliente;
    }

    /** O cliente que seguiu no último pedido ao InvoiceXpress. */
    private function clienteEnviado(): array
    {
        /** @var Request $pedido */
        $pedido = Http::recorded()->last()[0];

        return $pedido['invoice']['client'];
    }

    private function faturar(Service $servico): array
    {
        (new InvoiceVendorService($servico->vendor))->createServiceInvoice($servico->fresh());

        return $this->clienteEnviado();
    }

    // ------------------------------------------------------------------ o nome

    public function test_com_o_nome_completo_vai_o_nome_completo(): void
    {
        $this->assertSame('Ana Silva', $this->faturar($this->servicoDe($this->cliente('Ana', 'Silva')))['name']);
    }

    /** `users.name` é CONCAT(first_name, " ", last_name): sem apelido, fica vazio. */
    public function test_sem_apelido_vai_o_primeiro_nome(): void
    {
        $cliente = $this->cliente('Ana', null);
        $this->assertNull($cliente->fresh()->name, 'a coluna calculada fica vazia');

        $this->assertSame('Ana', $this->faturar($this->servicoDe($cliente))['name']);
    }

    public function test_sem_nome_nenhum_vai_consumidor_final(): void
    {
        $enviado = $this->faturar($this->servicoDe($this->cliente(null, null, ['nif' => null])));

        $this->assertSame('Consumidor final', $enviado['name']);
        $this->assertSame(999999990, $enviado['fiscal_id']);
    }

    // ---------------------------------------------------------------- o código

    /** O caso que punha o NIF de um cliente na fatura de outro. */
    public function test_dois_clientes_com_o_mesmo_nome_sao_dois_clientes(): void
    {
        $primeiro = $this->faturar($this->servicoDe($this->cliente('Ana', 'Silva')));
        $segundo = $this->faturar($this->servicoDe($this->cliente('Ana', 'Silva')));

        $this->assertSame($primeiro['name'], $segundo['name']);
        $this->assertNotSame($primeiro['code'], $segundo['code']);
    }

    public function test_dois_consumidores_finais_sao_dois_clientes(): void
    {
        $primeiro = $this->faturar($this->servicoDe($this->cliente(null, null, ['nif' => null])));
        $segundo = $this->faturar($this->servicoDe($this->cliente(null, null, ['nif' => null])));

        $this->assertNotSame($primeiro['code'], $segundo['code']);
    }

    public function test_o_mesmo_cliente_com_os_mesmos_dados_e_o_mesmo_cliente(): void
    {
        $cliente = $this->cliente('Ana', 'Silva');

        $this->assertSame(
            $this->faturar($this->servicoDe($cliente))['code'],
            $this->faturar($this->servicoDe($cliente))['code'],
        );
    }

    /** O InvoiceXpress ignora os dados de um cliente que já existe: um NIF novo é outro cliente. */
    public function test_quem_muda_de_nif_passa_a_ser_outro_cliente(): void
    {
        $cliente = $this->cliente('Ana', 'Silva', ['nif' => '123456789']);
        $antes = $this->faturar($this->servicoDe($cliente));

        $cliente->update(['nif' => '987654321']);
        $depois = $this->faturar($this->servicoDe($cliente));

        $this->assertNotSame($antes['code'], $depois['code']);
        $this->assertSame('987654321', (string) $depois['fiscal_id']);
    }

    // ------------------------------------------- os dados de faturação

    /** Decisão do André (13/10/2026): o nome e o NIF dos dados de faturação. */
    public function test_vao_o_nome_e_o_nif_dos_dados_de_faturacao(): void
    {
        $cliente = $this->comDadosDeFaturacao($this->cliente('Ana', 'Silva', ['nif' => '123456789']), 'ACME Lda', '501234567');

        $enviado = $this->faturar($this->servicoDe($cliente));

        $this->assertSame('ACME Lda', $enviado['name']);
        $this->assertSame('501234567', (string) $enviado['fiscal_id']);
        $this->assertSame('Rua da Empresa 10', $enviado['address']);
    }

    /** A app pré-preenche o NIF do pedido com o dos dados de faturação. */
    public function test_com_o_mesmo_nif_no_pedido_vao_os_dados_de_faturacao(): void
    {
        $cliente = $this->comDadosDeFaturacao($this->cliente('Ana', 'Silva'), 'ACME Lda', '501234567');

        $enviado = $this->faturar($this->servicoDe($cliente, '501234567'));

        $this->assertSame('ACME Lda', $enviado['name']);
        $this->assertSame('501234567', (string) $enviado['fiscal_id']);
    }

    /**
     * Outro NIF no checkout: é a pessoa, não a entidade dos dados de faturação.
     * O nome da empresa com o NIF de uma pessoa seria uma fatura errada.
     */
    public function test_outro_nif_no_pedido_vai_com_o_nome_da_pessoa(): void
    {
        $cliente = $this->comDadosDeFaturacao($this->cliente('Ana', 'Silva'), 'ACME Lda', '501234567');

        $enviado = $this->faturar($this->servicoDe($cliente, '234567899'));

        $this->assertSame('Ana Silva', $enviado['name']);
        $this->assertSame('234567899', (string) $enviado['fiscal_id']);
    }

    public function test_so_com_o_nome_nos_dados_de_faturacao_vai_esse_nome(): void
    {
        $cliente = $this->comDadosDeFaturacao($this->cliente('Ana', null, ['nif' => null]), 'Ana Maria Silva', null);

        $enviado = $this->faturar($this->servicoDe($cliente));

        $this->assertSame('Ana Maria Silva', $enviado['name']);
        $this->assertSame(999999990, $enviado['fiscal_id']);
    }

    public function test_dados_de_faturacao_novos_sao_outro_cliente(): void
    {
        $cliente = $this->cliente('Ana', 'Silva');
        $antes = $this->faturar($this->servicoDe($cliente));

        $this->comDadosDeFaturacao($cliente, 'ACME Lda', '501234567');
        $depois = $this->faturar($this->servicoDe($cliente->fresh()));

        $this->assertNotSame($antes['code'], $depois['code']);
    }

    // ------------------------------------------------------- as outras faturas

    public function test_a_taxa_de_cancelamento_segue_a_mesma_regra(): void
    {
        $servico = $this->servicoDe($this->cliente('Ana', null));

        (new InvoiceVendorService($servico->vendor))->createCancellationServiceInvoice($servico->fresh());

        $enviado = $this->clienteEnviado();
        $this->assertSame('Ana', $enviado['name']);
        $this->assertStringStartsWith('PQ'.$servico->customer_id.'-', $enviado['code']);
    }

    /** Na conta da Piquet, o cliente da taxa de cancelamento é o técnico. */
    public function test_a_taxa_ao_tecnico_segue_a_mesma_regra(): void
    {
        config(['services.invoiceExpress.api_key' => 'chave-de-teste', 'services.invoiceExpress.account_name' => 'piquet-teste']);
        $servico = $this->servicoDe($this->cliente('Ana', 'Silva'));
        $servico->vendor->user->update(['first_name' => 'Rui', 'last_name' => null]);

        (new SystemInvoiceService)->createCancellationServiceInvoice($servico->fresh());

        $enviado = $this->clienteEnviado();
        $this->assertSame('Rui', $enviado['name']);
        $this->assertStringStartsWith('PQ'.$servico->vendor->user_id.'-', $enviado['code']);
    }
}
