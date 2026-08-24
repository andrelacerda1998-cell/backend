<?php

namespace Tests\Feature;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\Vendor;
use App\Models\Vendor\Ratings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A avaliação do profissional é a nota que os CLIENTES lhe deram.
 *
 * Regressão de um bug com duas partes: `updateRatting()` lia
 * `rating_by_vendor` (a nota que o profissional dá ao cliente) e, quando não
 * havia avaliações, gravava 5 estrelas com uma avaliação fictícia.
 */
class VendorRatingsTest extends TestCase
{
    use RefreshDatabase;

    private OperationArea $area;

    private ServicesType $type;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = OperationArea::factory()->create();
        $this->type = ServicesType::factory()->create(['operation_area_id' => $this->area->id]);
        $this->vendor = Vendor::factory()->create();
        $this->vendor->operationAreas()->attach($this->area->id);
    }

    private function closedService(?int $byCustomer, ?int $byVendor = null): Service
    {
        return Service::factory()->create([
            'vendor_id' => $this->vendor->id,
            'services_type_id' => $this->type->id,
            'status' => ServiceStatus::CLOSED,
            'rating_by_customer' => $byCustomer,
            'rating_by_vendor' => $byVendor,
        ]);
    }

    private function rating(): ?Ratings
    {
        $this->vendor->refresh()->updateRatting();

        return Ratings::where('vendor_id', $this->vendor->id)
            ->where('operation_area_id', $this->area->id)
            ->first();
    }

    public function test_averages_the_ratings_customers_gave(): void
    {
        $this->closedService(5);
        $this->closedService(4);
        $this->closedService(3);

        $rating = $this->rating();

        $this->assertSame(4.0, $rating->average_rating);
        $this->assertSame(3, $rating->total_ratings);
    }

    public function test_ignores_the_rating_the_vendor_gave_the_customer(): void
    {
        // O profissional foi generoso com os clientes (5) e eles não foram com
        // ele (2). A nota que conta é a que ele recebeu.
        $this->closedService(byCustomer: 2, byVendor: 5);
        $this->closedService(byCustomer: 2, byVendor: 5);

        $this->assertSame(2.0, $this->rating()->average_rating);
    }

    public function test_no_ratings_means_null_not_five_stars(): void
    {
        $rating = $this->rating();

        $this->assertNull(
            $rating->average_rating,
            'sem avaliações não se inventa nota: mostrava 5 estrelas que ninguém deu'
        );
        $this->assertSame(0, $rating->total_ratings);
    }

    public function test_closed_services_without_a_rating_do_not_count(): void
    {
        // 40 serviços fechados e duas notas são duas avaliações, não quarenta.
        $this->closedService(5);
        $this->closedService(4);
        $this->closedService(null);
        $this->closedService(null);

        $rating = $this->rating();

        $this->assertSame(2, $rating->total_ratings);
        $this->assertSame(4.5, $rating->average_rating);
    }

    public function test_keeps_decimals_instead_of_rounding_to_whole_stars(): void
    {
        // Era `round()` sobre um int: 4,5 virava 5. A app do cliente mostra uma
        // casa decimal, portanto a precisão perdia-se por nada.
        $this->closedService(5);
        $this->closedService(4);

        $this->assertSame(4.5, $this->rating()->average_rating);
    }

    public function test_only_counts_services_of_that_operation_area(): void
    {
        $otherArea = OperationArea::factory()->create();
        $otherType = ServicesType::factory()->create(['operation_area_id' => $otherArea->id]);

        $this->closedService(5);

        Service::factory()->create([
            'vendor_id' => $this->vendor->id,
            'services_type_id' => $otherType->id,
            'status' => ServiceStatus::CLOSED,
            'rating_by_customer' => 1,
        ]);

        $this->assertSame(5.0, $this->rating()->average_rating);
    }

    public function test_unfinished_services_do_not_count(): void
    {
        $this->closedService(5);

        Service::factory()->create([
            'vendor_id' => $this->vendor->id,
            'services_type_id' => $this->type->id,
            'status' => ServiceStatus::ARRIVED,
            'rating_by_customer' => 1,
        ]);

        $this->assertSame(5.0, $this->rating()->average_rating);
    }
}
