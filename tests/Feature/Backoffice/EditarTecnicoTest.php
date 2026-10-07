<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Resources\VendorResource\Pages\EditVendor;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Editar um técnico no backoffice com um email já usado noutra conta
 * dava "Server error": a regra de unicidade tinha uma condição sempre falsa e
 * nunca corria, e era a base de dados a rejeitar.
 */
class EditarTecnicoTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function tecnico(string $email, string $nif): Vendor
    {
        $user = User::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Teste',
            'email' => $email,
            'nif' => $nif,
            'date_birthday' => now()->subYears(30)->toDateString(),
            'phone_number' => '+3519'.random_int(10000000, 99999999),
        ]);

        return Vendor::factory()->create([
            'user_id' => $user->id,
            'company_name' => 'Ana Teste',
            'iban' => 'PT50000201231234567890154',
        ]);
    }

    public function test_um_email_de_outra_conta_da_erro_no_campo_e_nao_server_error(): void
    {
        $this->tecnico('ja.existe@piquet.test', '111111111');
        $vendor = $this->tecnico('o.meu@piquet.test', '222222222');

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->fillForm(['user.email' => 'ja.existe@piquet.test', 'price_rate' => 8])
            ->call('save')
            ->assertHasFormErrors(['user.email' => 'unique']);

        $this->assertSame('o.meu@piquet.test', $vendor->user->fresh()->email);
    }

    public function test_dois_tecnicos_da_mesma_empresa_podem_ter_o_mesmo_nif(): void
    {
        // O NIF da empresa é partilhado pelos técnicos dela (62 e 418).
        $this->tecnico('colega@piquet.test', '333333333');
        $vendor = $this->tecnico('eu@piquet.test', '333333333');

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->fillForm(['price_rate' => 8])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(800, (int) $vendor->fresh()->getRawOriginal('price_rate'));
        $this->assertSame('333333333', $vendor->user->fresh()->nif);
    }

    public function test_mantendo_o_proprio_email_grava_o_valor_hora(): void
    {
        $vendor = $this->tecnico('mantem@piquet.test', '555555555');

        Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
            ->fillForm(['price_rate' => 8])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(800, (int) $vendor->fresh()->getRawOriginal('price_rate'));
    }
}
