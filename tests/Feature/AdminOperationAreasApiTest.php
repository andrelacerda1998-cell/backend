<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class AdminOperationAreasApiTest extends TestCase
{
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

    public function test_it_lists_operation_areas_with_counts(): void
    {
        $area = $this->makeArea('Canalização');
        $type = new ServicesType();
        $type->operation_area_id = $area->id;
        $type->time = 60;
        $type->setTranslations('name', ['en' => 'Fix', 'pt-pt' => 'Reparação']);
        $type->save();

        $this->withAuth()
            ->getJson('/api/v1/admin/operation-areas')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $area->id)
            ->assertJsonPath('data.items.0.name', 'Canalização')
            ->assertJsonPath('data.items.0.vendors_count', 0)
            ->assertJsonPath('data.items.0.services_types_count', 1);
    }

    public function test_it_searches_operation_areas_by_name(): void
    {
        $target = $this->makeArea('Canalização');
        $this->makeArea('Eletricista');

        $this->withAuth()
            ->getJson('/api/v1/admin/operation-areas?search=canaliza')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $target->id);
    }

    public function test_it_creates_an_operation_area(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/admin/operation-areas', [
            'name' => 'Jardinagem',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Jardinagem')
            ->assertJsonPath('data.vendors_count', 0)
            ->assertJsonPath('data.services_types_count', 0);

        $this->assertDatabaseCount('operation_areas', 1);
    }

    public function test_it_validates_name_required_on_create(): void
    {
        $this->withAuth()
            ->postJson('/api/v1/admin/operation-areas', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_it_updates_an_operation_area(): void
    {
        $area = $this->makeArea('Canalização');

        $this->withAuth()
            ->putJson("/api/v1/admin/operation-areas/{$area->id}", ['name' => 'Canalização e Gás'])
            ->assertOk()
            ->assertJsonPath('data.id', $area->id)
            ->assertJsonPath('data.name', 'Canalização e Gás');
    }

    public function test_it_returns_404_for_an_unknown_operation_area(): void
    {
        $this->withAuth()
            ->putJson('/api/v1/admin/operation-areas/999999', ['name' => 'X'])
            ->assertNotFound();
    }
}
