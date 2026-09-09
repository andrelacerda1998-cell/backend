<?php

namespace Tests\Feature;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\Vendor;
use App\Services\Matching\VendorRankingService;
use App\Settings\MatchingSettings;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O amortecedor de arranque acaba mais cedo para quem comeca mal.
 *
 * Ate as primeiras 5 avaliacoes, um profissional ordena como faixa A para a
 * nota poder estabilizar. Mas tres clientes seguidos a dar menos de 3 estrelas
 * nao e ruido — e um padrao. A partir dai a nota real conta, mesmo antes das
 * cinco.
 */
class MatchingBadStartTest extends TestCase
{
    use RefreshDatabase;

    private VendorRankingService $ranking;

    private ServicesType $tipo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);

        MatchingSettings::fake([
            'shortlist_size' => 3,
            'wave_size' => 6,
            'wave_interval_seconds' => 45,
            'max_waves' => 3,
            'vendor_response_seconds_immediate' => 60,
            'vendor_response_seconds_scheduled' => 1800,
            'customer_choice_seconds' => 120,
            'customer_choice_seconds_scheduled' => 1800,
            'checkout_seconds' => 300,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
        ]);

        $this->ranking = app(VendorRankingService::class);
        $this->tipo = ServicesType::factory()->create();
    }

    /** @param array<int, int|null> $notas pela ordem em que foram dadas */
    private function vendorCom(array $notas): Vendor
    {
        $vendor = Vendor::factory()->create();

        foreach (array_values($notas) as $i => $nota) {
            Service::factory()->create([
                'vendor_id' => $vendor->id,
                'services_type_id' => $this->tipo->id,
                'status' => ServiceStatus::CLOSED,
                'rating_by_customer' => $nota,
                'updated_at' => now()->subDays(count($notas) - $i),
            ]);
        }

        return $vendor;
    }

    /** Lê pela via de produção: a consulta que ordena as notas e decide. */
    private function faixaDe(Vendor $vendor): int
    {
        $ratings = $this->ranking->ratingsFor([$vendor->id], $this->tipo);
        $row = $ratings[$vendor->id];

        return $this->ranking->bandFor($row['avg'], $row['count'], $row['bad_start']);
    }

    private function arranqueMau(Vendor $vendor): bool
    {
        return $this->ranking->ratingsFor([$vendor->id], $this->tipo)[$vendor->id]['bad_start'];
    }

    public function test_tres_notas_seguidas_abaixo_de_tres_acabam_com_a_protecao(): void
    {
        $mau = $this->vendorCom([2, 1, 2]);

        $this->assertTrue($this->arranqueMau($mau));
        $this->assertSame(
            3,
            $this->faixaDe($mau),
            'com tres arranques abaixo de 3, a nota real passa a contar antes das cinco'
        );
    }

    public function test_duas_notas_ainda_estao_protegidas(): void
    {
        $duas = $this->vendorCom([1, 1]);

        $this->assertFalse($this->arranqueMau($duas), 'duas notas ainda nao sao um padrao');
        $this->assertSame(0, $this->faixaDe($duas));
    }

    public function test_uma_boa_no_meio_mantem_a_protecao(): void
    {
        // "As PRIMEIRAS 3 notas abaixo de 3" — se uma delas nao for, o arranque
        // nao correu mal e o amortecedor continua.
        $misto = $this->vendorCom([1, 4, 1]);

        $this->assertFalse($this->arranqueMau($misto), 'a segunda nota nao foi abaixo de 3');
        $this->assertSame(0, $this->faixaDe($misto));
    }

    public function test_a_quarta_nota_nao_apaga_o_arranque(): void
    {
        // Sao as PRIMEIRAS tres que decidem: recuperar depois nao devolve a
        // protecao, devolve nota — que e o que passa a contar.
        $recuperou = $this->vendorCom([1, 2, 2, 5]);

        $this->assertTrue($this->arranqueMau($recuperou));
        $this->assertSame(3, $this->faixaDe($recuperou));
    }

    public function test_a_ordem_das_notas_conta_e_nao_so_o_conjunto(): void
    {
        // O mesmo conjunto {1,1,4} em ordens diferentes da resultados
        // diferentes — e a prova de que a consulta ordena mesmo, em vez de
        // contar quantas ficaram abaixo de 3.
        $mauPrimeiro = $this->vendorCom([1, 1, 4]);
        $bomPrimeiro = $this->vendorCom([4, 1, 1]);

        $this->assertFalse($this->arranqueMau($mauPrimeiro), 'a terceira foi 4');
        $this->assertFalse($this->arranqueMau($bomPrimeiro), 'a primeira foi 4');
    }

    public function test_quem_passou_das_cinco_nao_e_afetado_por_esta_regra(): void
    {
        // Fora do amortecedor a nota ja contava. A regra nao lhes muda nada, e
        // nao vale uma consulta.
        $veterano = $this->vendorCom([1, 1, 1, 5, 5, 5]);

        $this->assertFalse($this->arranqueMau($veterano));
        $this->assertSame(2, $this->faixaDe($veterano), 'media 3,0 e exatamente o piso da faixa C');
    }
}
