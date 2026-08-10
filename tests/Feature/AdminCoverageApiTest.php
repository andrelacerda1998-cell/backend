<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\AllowedZone;
use App\Models\GeneralSettings\SurveyCity;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Cobertura por técnico — junta zonas abertas (allowed_zone/vendor_allowed_zones)
 * e cidades candidatas (survey_cities/vendor_city_votes). Ver nota extensa em
 * CoverageController.
 */
class AdminCoverageApiTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'allowed_zone', 'vendor_allowed_zones',
        'survey_cities', 'vendor_city_votes',
        'users', 'vendors', 'wallets',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function makeVendor(string $firstName = 'Ana', string $lastName = 'Silva'): Vendor
    {
        $user = User::factory()->create(['first_name' => $firstName, 'last_name' => $lastName]);

        return Vendor::create(['user_id' => $user->id, 'username' => 'tecnico_'.$user->id]);
    }

    public function test_it_lists_open_zones_ordered_by_city_with_their_technicians(): void
    {
        $sintra = AllowedZone::create(['city' => 'Sintra', 'district' => 'Lisboa']);
        $porto = AllowedZone::create(['city' => 'Porto', 'district' => 'Porto']);

        $vendor = $this->makeVendor('Ana', 'Silva');
        $vendor->allowedZones()->attach($porto->id);

        $this->withAuth()
            ->getJson('/api/v1/admin/coverage')
            ->assertOk()
            ->assertJsonCount(2, 'data.open')
            // ordenado por cidade: Porto antes de Sintra
            ->assertJsonPath('data.open.0.city', 'Porto')
            ->assertJsonPath('data.open.0.district', 'Porto')
            ->assertJsonCount(1, 'data.open.0.technicians')
            ->assertJsonPath('data.open.0.technicians.0.id', $vendor->id)
            ->assertJsonPath('data.open.0.technicians.0.name', 'Ana Silva')
            ->assertJsonPath('data.open.1.city', 'Sintra')
            ->assertJsonCount(0, 'data.open.1.technicians');
    }

    public function test_it_lists_candidate_cities_with_their_voters(): void
    {
        $coimbra = SurveyCity::create(['city' => 'Coimbra', 'district' => 'Centro', 'active' => true]);
        SurveyCity::create(['city' => 'Aveiro', 'district' => 'Centro', 'active' => false]);

        $vendor = $this->makeVendor('Bruno', 'Costa');
        $vendor->surveyCityVotes()->attach($coimbra->id);

        $this->withAuth()
            ->getJson('/api/v1/admin/coverage')
            ->assertOk()
            ->assertJsonCount(2, 'data.candidate')
            ->assertJsonPath('data.candidate.0.city', 'Aveiro')
            ->assertJsonPath('data.candidate.0.active', false)
            ->assertJsonCount(0, 'data.candidate.0.technicians')
            ->assertJsonPath('data.candidate.1.city', 'Coimbra')
            ->assertJsonPath('data.candidate.1.active', true)
            ->assertJsonCount(1, 'data.candidate.1.technicians')
            ->assertJsonPath('data.candidate.1.technicians.0.name', 'Bruno Costa');
    }

    public function test_it_keeps_open_zones_and_candidate_cities_independent(): void
    {
        AllowedZone::create(['city' => 'Lisboa', 'district' => 'Lisboa']);
        SurveyCity::create(['city' => 'Braga', 'district' => 'Braga', 'active' => true]);

        $response = $this->withAuth()->getJson('/api/v1/admin/coverage')->assertOk();

        $response->assertJsonCount(1, 'data.open')
            ->assertJsonCount(1, 'data.candidate')
            ->assertJsonPath('data.open.0.city', 'Lisboa')
            ->assertJsonPath('data.candidate.0.city', 'Braga');
    }

    public function test_it_presents_technician_contact_fields(): void
    {
        $zone = AllowedZone::create(['city' => 'Faro', 'district' => 'Algarve']);
        $vendor = $this->makeVendor('Carla', 'Ramos');
        $vendor->allowedZones()->attach($zone->id);

        $this->withAuth()
            ->getJson('/api/v1/admin/coverage')
            ->assertOk()
            ->assertJsonPath('data.open.0.technicians.0.id', $vendor->id)
            ->assertJsonPath('data.open.0.technicians.0.name', 'Carla Ramos')
            ->assertJsonStructure([
                'data' => [
                    'open' => [['id', 'city', 'district', 'technicians' => [['id', 'name', 'nif', 'email', 'phone_number', 'status']]]],
                ],
            ]);
    }
}
