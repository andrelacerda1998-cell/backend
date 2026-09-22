<?php

namespace Tests\Feature;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * As avaliações têm de voltar sem ninguém correr nada à mão.
 *
 * A migração de 24/08 esvaziou `vendor_ratings` de propósito — os valores
 * vinham da nota que o PROFISSIONAL deu ao CLIENTE — e deixou o recálculo para
 * o comando `vendors:recalculate-ratings`. Esse comando nunca correu em
 * produção e não ia correr: o entrypoint do contentor corre `migrate --force`
 * e mais nada.
 *
 * Mesma lição do catálogo de cidades: o que não está no caminho do deploy não
 * acontece.
 */
class AvaliacoesVoltamNoDeployTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
    }

    /**
     * O MESMO tipo de serviço nos dois, de propósito: a média é por área de
     * operação, e dois tipos diferentes dariam duas linhas separadas — que é
     * o comportamento certo, mas não é o que este teste mede.
     */
    private function servicoAvaliado(Vendor $vendor, ServicesType $tipo, int $nota): void
    {
        $vendor->operationAreas()->syncWithoutDetaching([$tipo->operation_area_id]);

        Service::factory()->create([
            'vendor_id' => $vendor->id,
            'customer_id' => User::factory()->create()->id,
            'services_type_id' => $tipo->id,
            'status' => ServiceStatus::CLOSED,
            'rating_by_customer' => $nota,
        ]);
    }

    public function test_o_recalculo_devolve_a_media_das_notas_dos_clientes(): void
    {
        $vendor = Vendor::factory()->create();
        $tipo = ServicesType::factory()->create();
        // Três: é o mínimo para a nota ser mostrada.
        foreach ([5, 4, 5] as $nota) {
            $this->servicoAvaliado($vendor, $tipo, $nota);
        }

        Artisan::call('vendors:recalculate-ratings');

        $avaliacao = $vendor->averageRating()->first();
        $this->assertNotNull($avaliacao, 'sem linha nenhuma, o ecrã continua vazio');
        $this->assertEqualsWithDelta(4.67, (float) $avaliacao->average_rating, 0.01, 'a média guarda decimais');
        $this->assertSame(3, (int) $avaliacao->total_ratings);
    }

    /**
     * Abaixo do mínimo a nota fica NULL de propósito: uma média de duas
     * avaliações não diz nada sobre ninguém, e uma delas fraca condenava quem
     * ainda não teve hipótese de mostrar trabalho. A contagem continua a ser
     * gravada — o que não se mostra é a média.
     */
    public function test_abaixo_do_minimo_nao_se_mostra_media(): void
    {
        $vendor = Vendor::factory()->create();
        $tipo = ServicesType::factory()->create();
        $this->servicoAvaliado($vendor, $tipo, 5);
        $this->servicoAvaliado($vendor, $tipo, 4);

        Artisan::call('vendors:recalculate-ratings');

        $avaliacao = $vendor->averageRating()->first();
        $this->assertNull($avaliacao?->average_rating, 'duas avaliações ainda não fazem uma nota');
        $this->assertSame(2, (int) $avaliacao?->total_ratings);
    }

    public function test_recalcular_duas_vezes_da_o_mesmo(): void
    {
        $vendor = Vendor::factory()->create();
        $tipo = ServicesType::factory()->create();
        foreach ([3, 4, 5] as $nota) {
            $this->servicoAvaliado($vendor, $tipo, $nota);
        }

        Artisan::call('vendors:recalculate-ratings');
        $primeiro = $vendor->averageRating()->first()?->average_rating;

        // A migração pode correr onde já foi corrida.
        Artisan::call('vendors:recalculate-ratings');

        $this->assertSame($primeiro, $vendor->averageRating()->first()?->average_rating);
    }

    public function test_um_profissional_sem_notas_fica_sem_media_e_nao_com_cinco(): void
    {
        $vendor = Vendor::factory()->create();

        Artisan::call('vendors:recalculate-ratings');

        $avaliacao = $vendor->averageRating()->first();
        $this->assertNull($avaliacao?->average_rating, 'não ter avaliações é um facto, não é nota máxima');
    }

    public function test_o_recalculo_nao_rebenta_sem_profissionais(): void
    {
        $this->assertSame(0, Vendor::count());

        // A migração corre em bases onde ainda não há ninguém.
        $this->assertSame(0, Artisan::call('vendors:recalculate-ratings'));
    }
}
