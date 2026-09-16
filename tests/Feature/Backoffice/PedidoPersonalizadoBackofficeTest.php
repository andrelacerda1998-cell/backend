<?php

namespace Tests\Feature\Backoffice;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Filament\Resources\ServicesResource\Pages\ViewService;
use App\Models\GeneralSettings\OperationArea;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A pagina do servico no backoffice tem de abrir num pedido personalizado e
 * mostrar a accao de o enviar aos profissionais. As closures do infolist e da
 * accao so correm ao renderizar — e ai que um erro apareceria.
 */
class PedidoPersonalizadoBackofficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        Role::findOrCreate('admin');

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    public function test_a_pagina_abre_e_mostra_o_pedido_e_a_accao_de_enviar(): void
    {
        OperationArea::factory()->create();

        $service = Service::factory()->create([
            'vendor_id' => null,
            'services_type_id' => null,
            'is_custom' => true,
            'custom_description' => 'Trocar a fechadura da porta da rua.',
            'status' => ServiceStatus::PENDING_REVIEW,
            'payment_status' => PaymentStatus::PENDING,
            'amount' => null,
            'amount_for_vendor' => null,
        ]);

        Livewire::test(ViewService::class, ['record' => $service->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Trocar a fechadura da porta da rua.')
            ->assertSee(__('backoffice/service.custom.heading'))
            ->assertSee(__('backoffice/service.custom.not_set'))
            ->assertActionVisible('dispatch_custom_request');
    }

    public function test_num_pedido_normal_nao_ha_seccao_nem_accao(): void
    {
        $service = Service::factory()->create();

        Livewire::test(ViewService::class, ['record' => $service->getRouteKey()])
            ->assertSuccessful()
            ->assertDontSee(__('backoffice/service.custom.heading'))
            ->assertActionHidden('dispatch_custom_request');
    }
}
