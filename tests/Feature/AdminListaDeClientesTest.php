<?php

namespace Tests\Feature;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A lista de clientes do backoffice: pesquisa pelo nome completo e pelo
 * telefone em qualquer formato, os bloqueados quando se pedem, quem tem um
 * pedido aberto, a ordem pelo último pedido e o resumo dos pedidos de cada um.
 *
 * As listas comparam-se só com os clientes que CADA teste criou: outras
 * classes deixam clientes na base, e não podem decidir se esta passa.
 */
class AdminListaDeClientesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $criados = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['services.admin_api.token' => 'a-valid-token']);

        // Papéis órfãos deixados por outras classes (users truncada, pivot
        // não) fariam um cliente novo herdar o papel de admin pelo id e
        // desaparecer da lista -- ver AdminCustomersApiTest. Dentro da
        // transação do teste: no fim volta tudo como estava.
        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereNotIn('model_id', DB::table('users')->select('id'))
            ->delete();
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function cliente(array $atributos = []): User
    {
        $cliente = User::factory()->create($atributos);
        $this->criados[] = $cliente->id;

        return $cliente;
    }

    private function pedido(User $cliente, ServiceStatus $status, ?int $centimos = 5000, int $creditoDeConvites = 0, bool $teste = false, ?CarbonInterface $em = null): int
    {
        $em ??= now();

        return DB::table('services')->insertGetId([
            'customer_id' => $cliente->id,
            'status' => $status->value,
            'payment_status' => PaymentStatus::PAID->value,
            'amount' => $centimos,
            'referral_credit_used' => $creditoDeConvites,
            'is_test' => $teste,
            'created_at' => $em,
            'updated_at' => $em,
        ]);
    }

    /** Os ids devolvidos, PELA ORDEM da resposta, só dos criados aqui. */
    private function ids(string $query = ''): array
    {
        return collect($this->itensPorOrdem($query))
            ->pluck('id')
            ->filter(fn (int $id) => in_array($id, $this->criados, true))
            ->values()
            ->all();
    }

    private function itensPorOrdem(string $query = ''): array
    {
        $separador = str_contains($query, '?') ? '&' : '?';

        return $this->api()->getJson('/api/v1/admin/customers'.$query.$separador.'per_page=100')->assertOk()->json('data.items');
    }

    /** @return array<int, array> */
    private function itens(string $query = ''): array
    {
        return collect($this->itensPorOrdem($query))->keyBy('id')->all();
    }

    // ----------------------------------------------------------- pesquisa

    public function test_procura_pelo_nome_completo(): void
    {
        $ana = $this->cliente(['first_name' => 'Ana', 'last_name' => 'Costa', 'email' => 'cliente1@example.test']);
        $this->cliente(['first_name' => 'Ana', 'last_name' => 'Marques', 'email' => 'cliente2@example.test']);
        $this->cliente(['first_name' => 'Rita', 'last_name' => 'Costa', 'email' => 'cliente3@example.test']);

        $this->assertSame([$ana->id], $this->ids('?search='.urlencode('Ana Costa')));
        $this->assertSame([$ana->id], $this->ids('?search='.urlencode('  ana   COSTA ')));
    }

    public function test_procura_pelo_telefone_em_qualquer_formato(): void
    {
        $alvo = $this->cliente(['phone_number' => '+351912345678']);
        $this->cliente(['phone_number' => '+351936000000']);
        $antigo = $this->cliente();
        // Gravado antes da normalização: sem indicativo e com espaços.
        DB::table('users')->where('id', $antigo->id)->update(['phone_number' => '913 000 111']);

        foreach (['912345678', '912 345 678', '+351 912 345 678', '00351912345678', '912-345-678', '+351912345678'] as $formato) {
            $this->assertSame([$alvo->id], $this->ids('?search='.urlencode($formato)), "Formato: {$formato}");
        }

        $this->assertSame([$antigo->id], $this->ids('?search='.urlencode('+351 913 000 111')));
    }

    public function test_procura_pelo_email_e_pelo_nif(): void
    {
        $alvo = $this->cliente(['email' => 'ana.costa@example.test', 'nif' => '245678901']);
        $this->cliente(['email' => 'bruno@example.test', 'nif' => '198765432']);

        $this->assertSame([$alvo->id], $this->ids('?search=ana.costa@example'));
        $this->assertSame([$alvo->id], $this->ids('?search=245678901'));
    }

    // ---------------------------------------------------------- bloqueados

    public function test_os_bloqueados_so_aparecem_quando_se_pedem(): void
    {
        $ativo = $this->cliente(['first_name' => 'Ana', 'last_name' => 'Ativa', 'email' => 'ativa@example.test']);
        $bloqueado = $this->cliente(['first_name' => 'Ana', 'last_name' => 'Bloqueada', 'email' => 'bloqueada@example.test']);
        $bloqueado->delete();

        $this->assertSame([$ativo->id], $this->ids());
        $this->assertEqualsCanonicalizing([$bloqueado->id, $ativo->id], $this->ids('?incluir_bloqueados=1'));
        // Só os bloqueados, como sempre.
        $this->assertSame([$bloqueado->id], $this->ids('?blocked=1'));

        $itens = $this->itens('?incluir_bloqueados=1');
        $this->assertNotNull($itens[$bloqueado->id]['blocked_at']);
        $this->assertNull($itens[$ativo->id]['blocked_at']);

        // E com pesquisa: quem liga a dizer que não consegue entrar.
        $this->assertSame([$bloqueado->id], $this->ids('?incluir_bloqueados=1&search=Bloqueada'));
    }

    // ---------------------------------------------------- pedidos abertos

    public function test_filtra_quem_tem_um_pedido_aberto(): void
    {
        $aProcura = $this->cliente();
        $this->pedido($aProcura, ServiceStatus::MATCHING);
        $porCobrar = $this->cliente();
        $this->pedido($porCobrar, ServiceStatus::CLOSED_PENDING_PAYMENT);
        $soFechados = $this->cliente();
        $this->pedido($soFechados, ServiceStatus::CLOSED);
        $this->pedido($soFechados, ServiceStatus::CANCELED);
        $soDeTeste = $this->cliente();
        $this->pedido($soDeTeste, ServiceStatus::ACCEPTED, teste: true);
        $this->cliente();

        $this->assertEqualsCanonicalizing([$aProcura->id, $porCobrar->id], $this->ids('?com_pedido_aberto=1'));
    }

    // -------------------------------------------------------------- ordem

    public function test_ordena_pelo_ultimo_pedido_com_quem_nunca_pediu_no_fim(): void
    {
        $antigo = $this->cliente();
        $this->pedido($antigo, ServiceStatus::CLOSED, em: now()->subDays(10));
        $recente = $this->cliente();
        $this->pedido($recente, ServiceStatus::CLOSED, em: now()->subDays(20));
        $this->pedido($recente, ServiceStatus::MATCHING, em: now()->subDay());
        $nunca = $this->cliente();
        // Um pedido de teste não conta: é como se nunca tivesse pedido.
        $soDeTeste = $this->cliente();
        $this->pedido($soDeTeste, ServiceStatus::CLOSED, teste: true);

        $this->assertSame([$recente->id, $antigo->id, $soDeTeste->id, $nunca->id], $this->ids('?sort=ultimo_pedido'));
    }

    public function test_a_ordem_de_sempre_nao_muda(): void
    {
        $velho = $this->cliente(['created_at' => now()->subDays(3)]);
        $novo = $this->cliente(['created_at' => now()->subDay()]);
        $this->pedido($velho, ServiceStatus::CLOSED);

        $this->assertSame([$novo->id, $velho->id], $this->ids());
        $this->assertSame([$novo->id, $velho->id], $this->ids('?sort=outra_coisa'));
    }

    // ------------------------------------------------------------- resumo

    public function test_cada_cliente_traz_o_resumo_dos_pedidos(): void
    {
        $this->travelTo(now()->startOfSecond());
        $ultimo = now()->subHours(3);

        $cliente = $this->cliente();
        $this->pedido($cliente, ServiceStatus::CLOSED, 4000, em: now()->subDays(5));
        // 30 € com 5 € de crédito de convites: o cliente pagou 25 €.
        $this->pedido($cliente, ServiceStatus::CLOSED, 3000, creditoDeConvites: 500, em: now()->subDays(4));
        // Ainda por cobrar: é aberto e não conta no gasto.
        $this->pedido($cliente, ServiceStatus::CLOSED_PENDING_PAYMENT, 9999, em: now()->subDays(3));
        $this->pedido($cliente, ServiceStatus::CANCELED, 7777, em: now()->subDays(2));
        $this->pedido($cliente, ServiceStatus::MATCHING, null, em: $ultimo);
        // De teste: não conta para nada.
        $this->pedido($cliente, ServiceStatus::CLOSED, 100000, teste: true, em: now());

        $semPedidos = $this->cliente();

        $itens = $this->itens();

        $this->assertSame(5, $itens[$cliente->id]['services_count']);
        $this->assertSame(2, $itens[$cliente->id]['open_services_count']);
        $this->assertSame($ultimo->toIso8601String(), $itens[$cliente->id]['last_service_at']);
        $this->assertEquals(65.0, $itens[$cliente->id]['total_spent']);

        $this->assertSame(0, $itens[$semPedidos->id]['services_count']);
        $this->assertSame(0, $itens[$semPedidos->id]['open_services_count']);
        $this->assertNull($itens[$semPedidos->id]['last_service_at']);
        $this->assertEquals(0, $itens[$semPedidos->id]['total_spent']);

        // A ficha diz o mesmo.
        $ficha = $this->api()->getJson("/api/v1/admin/customers/{$cliente->id}")->assertOk();
        $this->assertSame(5, $ficha->json('data.services_count'));
        $this->assertSame(2, $ficha->json('data.open_services_count'));
        $this->assertSame($ultimo->toIso8601String(), $ficha->json('data.last_service_at'));
        $this->assertEquals(65.0, $ficha->json('data.total_spent'));
    }

    public function test_bloquear_e_reativar_devolvem_o_resumo(): void
    {
        $cliente = $this->cliente();
        $this->pedido($cliente, ServiceStatus::CLOSED, 4000);
        $this->pedido($cliente, ServiceStatus::ACCEPTED);

        $this->api()->putJson("/api/v1/admin/customers/{$cliente->id}/block")->assertOk()
            ->assertJsonPath('data.services_count', 2)
            ->assertJsonPath('data.open_services_count', 1);

        $this->api()->putJson("/api/v1/admin/customers/{$cliente->id}/restore")->assertOk()
            ->assertJsonPath('data.services_count', 2)
            ->assertJsonPath('data.blocked_at', null);
    }

    /** O resumo sai da consulta da página, e não de uma consulta por cliente. */
    public function test_o_resumo_nao_faz_uma_consulta_por_cliente(): void
    {
        foreach (range(1, 4) as $i) {
            $this->pedido($this->cliente(), ServiceStatus::CLOSED);
        }

        DB::enableQueryLog();
        $this->api()->getJson('/api/v1/admin/customers?per_page=100')->assertOk();
        $consultas = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        foreach (['services_count', 'open_services_count', 'last_service_at', 'total_spent_cents'] as $coluna) {
            $this->assertCount(1, $consultas->filter(fn ($q) => str_contains($q, $coluna)), $coluna);
        }
    }
}
