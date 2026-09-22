<?php

namespace Tests\Unit\Matching;

use App\Models\Vendor;
use App\Services\Matching\RankedVendor;
use App\Services\Matching\VendorRankingService;
use App\Settings\MatchingSettings;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * O raio do matching.
 *
 * O motor não tinha predicado geográfico nenhum: a distância entrava só como
 * terceiro critério de desempate. Medido na auditoria, para o mesmo serviço em
 * Lisboa: um profissional a 1 km por 34,11 € e outro a 398 km por 554,98 €,
 * lado a lado. E o cliente lia "Avisámos os técnicos da tua zona".
 *
 * Não é exclusão dura, por decisão de produto: quem está dentro é convidado
 * primeiro, e o raio abre-se quando não sobra ninguém dentro — em zonas com
 * pouca cobertura o pedido continua a ter hipótese.
 */
class RaioDoMatchingTest extends TestCase
{
    private VendorRankingService $ranking;

    protected function setUp(): void
    {
        parent::setUp();

        MatchingSettings::fake([
            'shortlist_size' => 3,
            'wave_size' => 6,
            'wave_interval_seconds' => 45,
            'max_waves' => 3,
            'vendor_response_seconds_immediate' => 60,
            'vendor_response_seconds_scheduled' => 180,
            'customer_choice_seconds' => 180,
            'customer_choice_seconds_scheduled' => 180,
            'customer_choice_seconds_custom' => 3600,
            'checkout_seconds' => 300,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
            'request_deadline_seconds' => 180,
            'max_radius_km' => 50,
        ]);

        $this->ranking = app(VendorRankingService::class);
    }

    private function aKm(string $nome, float $km): RankedVendor
    {
        $vendor = new Vendor;
        $vendor->id = crc32($nome);

        return new RankedVendor(
            vendor: $vendor,
            ratingAverage: 4.5,
            ratingCount: 10,
            ratingBand: $this->ranking->bandFor(4.5, 10),
            distance: $km,
            quotedAmount: 3411,
            quotedAmountForVendor: 2558,
        );
    }

    private function nomes(Collection $c): array
    {
        return $c->map(fn (RankedVendor $v) => $v->vendor->id)->all();
    }

    public function test_quem_esta_longe_nao_aparece_ao_lado_de_quem_esta_perto(): void
    {
        $perto = $this->aKm('da-rua', 1.0);
        $longe = $this->aKm('do-porto', 398.0);

        $resultado = $this->ranking->dentroDoRaio(collect([$perto, $longe]));

        $this->assertSame([$perto->vendor->id], $this->nomes($resultado));
    }

    public function test_o_limite_e_inclusivo(): void
    {
        $naFronteira = $this->aKm('a-50km', 50.0);
        $logoAseguir = $this->aKm('a-51km', 51.0);

        $resultado = $this->ranking->dentroDoRaio(collect([$naFronteira, $logoAseguir]));

        $this->assertSame([$naFronteira->vendor->id], $this->nomes($resultado));
    }

    public function test_sem_ninguem_dentro_o_raio_abre_se(): void
    {
        // Zona sem cobertura: mais vale uma proposta longe do que pedido nenhum.
        $um = $this->aKm('a-120km', 120.0);
        $outro = $this->aKm('a-398km', 398.0);

        $resultado = $this->ranking->dentroDoRaio(collect([$um, $outro]));

        $this->assertCount(2, $resultado, 'não é exclusão dura: sem ninguém dentro, entram os de fora');
    }

    public function test_com_o_raio_a_zero_nao_se_filtra_nada(): void
    {
        MatchingSettings::fake(['max_radius_km' => 0]);
        $ranking = app(VendorRankingService::class);

        $resultado = $ranking->dentroDoRaio(collect([$this->aKm('longe', 398.0)]));

        $this->assertCount(1, $resultado, 'o raio é uma definição: a zero, desliga-se');
    }
}
