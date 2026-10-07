<?php

namespace Tests\Feature\Backoffice;

use App\Enums\Services\ServiceStatus;
use App\Filament\Resources\ServicesResource;
use App\Filament\Resources\VendorResource\Pages\EditVendor;
use App\Filament\Resources\VendorResource\RelationManagers\ServicesRelationManager;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A cor do estado de um serviço esquecia `Expired3DS`, e o `match` rebentava.
 * Qualquer tabela com um serviço nesse estado dava "Server error" — incluindo
 * a página de editar o técnico, que mostra os serviços dele. Foi o que
 * impediu gravar o técnico 418 a 8 €/h (06/10/2026).
 */
class CorDoEstadoDoServicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_todos_os_estados_tem_cor(): void
    {
        foreach (ServiceStatus::cases() as $estado) {
            $this->assertContains(
                ServicesResource::corDoEstado($estado),
                ['warning', 'danger', 'success', 'gray'],
                "Sem cor para {$estado->name}",
            );
        }
    }

    public function test_a_tabela_de_servicos_do_tecnico_abre_com_um_servico_expired3ds(): void
    {
        $this->seed(GenderSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        Role::findOrCreate('admin');
        Role::findOrCreate('super-admin');
        $admin = User::factory()->create();
        $admin->assignRole(['admin', 'super-admin']);
        $this->actingAs($admin);

        $vendor = Vendor::factory()->create();
        Service::factory()->create(['vendor_id' => $vendor->id, 'status' => ServiceStatus::EXPIRED_3DS]);

        Livewire::test(ServicesRelationManager::class, [
            'ownerRecord' => $vendor,
            'pageClass' => EditVendor::class,
        ])->assertOk();
    }
}
