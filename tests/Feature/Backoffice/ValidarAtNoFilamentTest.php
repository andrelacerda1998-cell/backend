<?php

namespace Tests\Feature\Backoffice;

use App\Enums\Services\AddressType;
use App\Filament\Resources\VendorResource\Pages\ViewVendor;
use App\Models\Address;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeInvoiceVendorService;
use Tests\TestCase;

/**
 * O "Validar AT" do Filament e o do backoffice são o mesmo: as mesmas
 * condições e os mesmos passos (ValidacaoDaAt). Aqui, o lado do Filament.
 *
 * O InvoiceXpress é um duplo em memória: nenhum teste fala com o verdadeiro.
 */
class ValidarAtNoFilamentTest extends TestCase
{
    use RefreshDatabase;

    private FakeInvoiceVendorService $invoiceXpress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        Role::findOrCreate('admin');
        Role::findOrCreate('super-admin');

        $admin = User::factory()->create();
        $admin->assignRole(['admin', 'super-admin']);
        $this->actingAs($admin);

        $this->invoiceXpress = FakeInvoiceVendorService::ligar();
    }

    /** Um técnico com tudo o que a validação precisa, menos o que o teste mudar. */
    private function tecnico(array $dados = [], bool $morada = true): Vendor
    {
        $user = User::factory()->create(['phone_number' => '+3519'.random_int(10000000, 99999999)]);

        if ($morada) {
            Address::create([
                'user_id' => $user->id,
                'street_name' => 'Rua do Teste',
                'street_number' => '1',
                'postal_code' => '1000-001',
                'city' => 'Lisboa',
                'state' => 'Lisboa',
                'country' => 'Portugal',
                'address_type' => AddressType::FISCAL_ADDRESS,
            ]);
        }

        $vendor = Vendor::factory()->create(['user_id' => $user->id, 'company_name' => 'Ana Teste']);
        $vendor->forceFill(array_merge([
            'invoice_workspace' => 'ws-teste',
            'auth_token' => 'chave-de-teste',
            'at_user' => '123456789/1',
            'at_password' => 'segredo',
            'at_valid' => false,
        ], $dados))->save();

        return $vendor->fresh();
    }

    private function pagina(Vendor $vendor)
    {
        return Livewire::test(ViewVendor::class, ['record' => $vendor->getRouteKey()]);
    }

    /**
     * Sem a barra o `at_ready` nunca fica verdadeiro: validar marcava a AT como
     * válida e o técnico continuava travado. O botão fica desativado.
     */
    public function test_subutilizador_sem_a_barra_desativa_o_botao(): void
    {
        $this->pagina($this->tecnico(['at_user' => '123456789']))
            ->assertActionDisabled('validateAtCredentials');
    }

    public function test_sem_conta_de_faturacao_desativa_o_botao(): void
    {
        $this->pagina($this->tecnico(['invoice_workspace' => '', 'auth_token' => null]))
            ->assertActionDisabled('validateAtCredentials');
    }

    public function test_sem_morada_desativa_o_botao(): void
    {
        $this->pagina($this->tecnico([], morada: false))
            ->assertActionDisabled('validateAtCredentials');
    }

    public function test_com_tudo_valida_pelos_mesmos_passos_do_backoffice(): void
    {
        $vendor = $this->tecnico();

        $this->pagina($vendor)
            ->assertActionEnabled('validateAtCredentials')
            ->callAction('validateAtCredentials')
            ->assertNotified('Credenciais AT válidas');

        $this->assertSame(['dados_fiscais', 'comunicacao_at', 'serie'], $this->invoiceXpress->passos());
        $this->assertTrue($vendor->fresh()->at_valid);
        $this->assertNotNull($vendor->fresh()->at_validated_at);
    }

    public function test_credenciais_recusadas_deixam_a_at_por_validar(): void
    {
        $this->invoiceXpress->credenciaisDaAtRecusadas = true;
        $vendor = $this->tecnico(['at_valid' => true, 'at_validated_at' => '2026-10-01 10:00:00']);

        $this->pagina($vendor)
            ->callAction('validateAtCredentials')
            ->assertNotified('Credenciais AT inválidas');

        $this->assertFalse($vendor->fresh()->at_valid);
        $this->assertSame('2026-10-01 10:00:00', $vendor->fresh()->at_validated_at->format('Y-m-d H:i:s'));
    }
}
