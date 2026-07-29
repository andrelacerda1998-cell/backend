<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class AdminServicesTypesApiTest extends TestCase
{
    // DatabaseTruncation por defeito (ver nota extensa em AdminVendorsApiTest):
    // garante 'services_types'/'operation_areas' limpas antes de cada teste,
    // independentemente de que outra classe correu antes.
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['services_types', 'operation_areas'];

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function makeArea(string $name = 'Canalização'): OperationArea
    {
        $area = new OperationArea();
        $area->setTranslations('name', ['en' => $name, 'pt-pt' => $name]);
        $area->save();

        return $area;
    }

    private function makeType(OperationArea $area, array $attrs = []): ServicesType
    {
        $type = new ServicesType();
        $type->operation_area_id = $area->id;
        $type->time = $attrs['time'] ?? 60;
        $type->starts_from = $attrs['starts_from'] ?? null;
        $name = $attrs['name'] ?? 'Instalação de esquentador';
        $type->setTranslations('name', ['en' => $name, 'pt-pt' => $name]);
        $type->save();

        return $type;
    }

    public function test_it_lists_services_types_with_category_and_vendor_count(): void
    {
        $area = $this->makeArea('Canalização');
        $type = $this->makeType($area, ['name' => 'Instalação de esquentador', 'time' => 90]);

        $this->withAuth()
            ->getJson('/api/v1/admin/services-types')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $type->id)
            ->assertJsonPath('data.items.0.name', 'Instalação de esquentador')
            ->assertJsonPath('data.items.0.operation_area_id', $area->id)
            ->assertJsonPath('data.items.0.operation_area_name', 'Canalização')
            ->assertJsonPath('data.items.0.time', 90)
            ->assertJsonPath('data.items.0.vendors_count', 0);
    }

    public function test_it_searches_services_types_by_name(): void
    {
        $area = $this->makeArea();
        $target = $this->makeType($area, ['name' => 'Reparação de torneira']);
        $this->makeType($area, ['name' => 'Instalação de ar condicionado']);

        $this->withAuth()
            ->getJson('/api/v1/admin/services-types?search=torneira')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $target->id);
    }

    public function test_it_filters_services_types_by_operation_area(): void
    {
        $plumbing = $this->makeArea('Canalização');
        $electrical = $this->makeArea('Eletricista');
        $target = $this->makeType($plumbing, ['name' => 'Reparação de torneira']);
        $this->makeType($electrical, ['name' => 'Instalação elétrica']);

        $this->withAuth()
            ->getJson("/api/v1/admin/services-types?operation_area_id={$plumbing->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $target->id);
    }

    public function test_it_creates_a_services_type(): void
    {
        $area = $this->makeArea('Canalização');

        $response = $this->withAuth()->postJson('/api/v1/admin/services-types', [
            'name' => 'Desentupimento de canos',
            'operation_area_id' => $area->id,
            'time' => 45,
            'starts_from' => 30,
            'includes' => ['Diagnóstico', 'Mão de obra'],
            'excludes' => ['Peças'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Desentupimento de canos')
            ->assertJsonPath('data.operation_area_id', $area->id)
            ->assertJsonPath('data.time', 45)
            ->assertJsonPath('data.starts_from', 30)
            ->assertJsonPath('data.includes', ['Diagnóstico', 'Mão de obra'])
            ->assertJsonPath('data.excludes', ['Peças']);

        $this->assertDatabaseCount('services_types', 1);
    }

    public function test_it_validates_required_fields_on_create(): void
    {
        $this->withAuth()
            ->postJson('/api/v1/admin/services-types', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'operation_area_id', 'time']);
    }

    public function test_it_rejects_an_unknown_operation_area_on_create(): void
    {
        $this->withAuth()
            ->postJson('/api/v1/admin/services-types', [
                'name' => 'Serviço qualquer',
                'operation_area_id' => 999999,
                'time' => 30,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['operation_area_id']);
    }

    public function test_it_updates_a_services_type(): void
    {
        $area = $this->makeArea('Canalização');
        $type = $this->makeType($area, ['name' => 'Instalação de esquentador', 'time' => 60]);

        $this->withAuth()
            ->putJson("/api/v1/admin/services-types/{$type->id}", ['time' => 75])
            ->assertOk()
            ->assertJsonPath('data.id', $type->id)
            ->assertJsonPath('data.time', 75)
            ->assertJsonPath('data.name', 'Instalação de esquentador');
    }

    public function test_it_moves_a_services_type_to_another_category(): void
    {
        $plumbing = $this->makeArea('Canalização');
        $electrical = $this->makeArea('Eletricista');
        $type = $this->makeType($plumbing);

        $this->withAuth()
            ->putJson("/api/v1/admin/services-types/{$type->id}", ['operation_area_id' => $electrical->id])
            ->assertOk()
            ->assertJsonPath('data.operation_area_id', $electrical->id)
            ->assertJsonPath('data.operation_area_name', 'Eletricista');
    }

    public function test_it_returns_404_for_an_unknown_services_type(): void
    {
        $this->withAuth()
            ->putJson('/api/v1/admin/services-types/999999', ['time' => 30])
            ->assertNotFound();
    }
}
