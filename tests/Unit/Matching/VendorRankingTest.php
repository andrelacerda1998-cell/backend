<?php

namespace Tests\Unit\Matching;

use App\Models\Vendor;
use App\Services\Matching\RankedVendor;
use App\Services\Matching\VendorRankingService;
use App\Settings\MatchingSettings;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Núcleo puro do ranking: faixas de avaliação, ordenação pela prioridade do
 * negócio, e a vaga reservada a quem ainda não tem avaliações.
 *
 * Sem BD — o que se testa aqui é a decisão, não a consulta.
 */
class VendorRankingTest extends TestCase
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
            'vendor_response_seconds_scheduled' => 1800,
            'customer_choice_seconds' => 120,
            'customer_choice_seconds_scheduled' => 1800,
            'checkout_seconds' => 300,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
        ]);

        $this->ranking = app(VendorRankingService::class);
    }

    private function candidate(
        string $name,
        ?float $rating,
        int $ratingCount,
        int $amount,
        float $distance,
    ): RankedVendor {
        $vendor = new Vendor;
        $vendor->id = crc32($name);
        $vendor->setAttribute('display_name', $name);

        return new RankedVendor(
            vendor: $vendor,
            ratingAverage: $rating,
            ratingCount: $ratingCount,
            // A MESMA regra da producao, e nao uma copia: se o helper calculasse
            // a faixa por fora, os testes continuariam verdes com o ranking
            // partido.
            ratingBand: $this->ranking->bandFor($rating, $ratingCount),
            distance: $distance,
            quotedAmount: $amount,
            quotedAmountForVendor: (int) round($amount * 0.7),
        );
    }

    /** @param Collection<int, RankedVendor> $list */
    private function names(Collection $list): array
    {
        return $list->map(fn (RankedVendor $c) => $c->vendor->getAttribute('display_name'))->all();
    }

    public function test_bands_group_ratings_so_price_can_matter(): void
    {
        $this->assertSame(0, $this->ranking->band(5.0));
        $this->assertSame(0, $this->ranking->band(4.5));
        $this->assertSame(1, $this->ranking->band(4.49));
        $this->assertSame(1, $this->ranking->band(4.0));
        $this->assertSame(2, $this->ranking->band(3.0));
        $this->assertSame(3, $this->ranking->band(2.9));
    }

    public function test_better_band_wins_even_when_more_expensive(): void
    {
        $list = collect([
            $this->candidate('barato_mal_avaliado', 4.1, 30, 2000, 1.0),
            $this->candidate('caro_bem_avaliado', 4.8, 30, 5000, 9.0),
        ]);

        $this->assertSame(
            ['caro_bem_avaliado', 'barato_mal_avaliado'],
            $this->names($this->ranking->shortlist($list))
        );
    }

    public function test_price_decides_inside_the_same_band(): void
    {
        // 4,9 e 4,6 caem os dois na faixa A. Sem faixas, o 4,9 ganhava sempre e
        // o preço nunca chegava a contar.
        $list = collect([
            $this->candidate('caro', 4.9, 30, 5000, 1.0),
            $this->candidate('barato', 4.6, 30, 3000, 8.0),
        ]);

        $this->assertSame(['barato', 'caro'], $this->names($this->ranking->shortlist($list)));
    }

    public function test_distance_only_decides_when_band_and_price_tie(): void
    {
        $list = collect([
            $this->candidate('longe', 4.7, 30, 3000, 12.0),
            $this->candidate('perto', 4.6, 30, 3000, 2.0),
        ]);

        $this->assertSame(['perto', 'longe'], $this->names($this->ranking->shortlist($list)));
    }

    public function test_quem_ainda_nao_tem_avaliacoes_arranca_na_faixa_a(): void
    {
        // Antes ficava atras de toda a gente, incluindo de quem tem 2,0. Com o
        // corte para o ecra do cliente a ser por rank, isso era uma armadilha
        // fechada: nunca visto, logo nunca avaliado, logo nunca visto.
        $list = collect([
            $this->candidate('sem_avaliacoes', null, 0, 1000, 0.5),
            $this->candidate('fraco', 2.0, 30, 9000, 20.0),
        ]);

        $ranked = $this->ranking->shortlist($list);

        $this->assertSame(['sem_avaliacoes', 'fraco'], $this->names($ranked));
    }

    public function test_o_amortecedor_dura_ate_as_cinco_avaliacoes(): void
    {
        // A quinta avaliacao e o momento em que a nota passa a contar. Com 4 a
        // media ainda nao decide; com 5 decide.
        $quase = $this->candidate('com_quatro', 2.0, 4, 9000, 20.0);
        $ja = $this->candidate('com_cinco', 2.0, 5, 9000, 20.0);

        $this->assertSame(0, $quase->ratingBand, 'com 4 avaliacoes ainda esta na faixa A');
        $this->assertSame(3, $ja->ratingBand, 'a quinta avaliacao poe a media a contar');
    }

    public function test_o_amortecedor_protege_tambem_quem_comecou_mal(): void
    {
        // CONSEQUENCIA ASSUMIDA, coberta de proposito para nao ser descoberta
        // em producao: quatro notas de 1 estrela continuam a ordenar como
        // faixa A. E o preco de deixar a nota estabilizar antes de contar.
        //
        // Se isto doer, a saida nao e baixar o limiar as cegas — e olhar para
        // `new_vendor_min_ratings`, que e uma definicao editavel.
        $list = collect([
            $this->candidate('comecou_mal', 1.0, 4, 1000, 0.5),
            $this->candidate('solido', 4.9, 50, 9000, 20.0),
        ]);

        $ranked = $this->ranking->shortlist($list);

        $this->assertSame(
            ['comecou_mal', 'solido'],
            $this->names($ranked),
            'mesma faixa, e o mais barato e mais perto ganha — mesmo com quatro 1 estrela'
        );
    }

    public function test_reserves_the_last_slot_for_a_newcomer(): void
    {
        $list = collect([
            $this->candidate('veterano_1', 5.0, 40, 3000, 1.0),
            $this->candidate('veterano_2', 4.9, 40, 3100, 1.0),
            $this->candidate('veterano_3', 4.8, 40, 3200, 1.0),
            $this->candidate('veterano_4', 4.7, 40, 3300, 1.0),
            $this->candidate('novato', null, 0, 9000, 30.0),
        ]);

        $short = $this->ranking->shortlist($list);
        $names = $this->names($short);

        $this->assertCount(3, $short);
        $this->assertContains('novato', $names, 'sem a vaga reservada, a oferta nova nunca entra');
        $this->assertNotContains('veterano_3', $names, 'o novato ocupa a última vaga');
        $this->assertSame(['veterano_1', 'veterano_2'], array_slice($names, 0, 2));

        $newcomer = $short->first(fn (RankedVendor $c) => $c->vendor->getAttribute('display_name') === 'novato');
        $this->assertTrue($newcomer->isNewVendorSlot);
    }

    public function test_does_not_displace_anyone_when_a_newcomer_already_qualified(): void
    {
        $list = collect([
            $this->candidate('veterano_1', 5.0, 40, 3000, 1.0),
            $this->candidate('novato_bom', 4.9, 2, 2000, 1.0),
            $this->candidate('veterano_2', 4.8, 40, 3200, 1.0),
            $this->candidate('veterano_3', 4.7, 40, 3300, 1.0),
        ]);

        $short = $this->ranking->shortlist($list);
        $names = $this->names($short);

        $this->assertCount(3, $short);
        $this->assertContains('novato_bom', $names);
        $this->assertContains('veterano_2', $names, 'a vaga já estava ocupada por um recém-chegado');
        $this->assertFalse(
            $short->first(fn (RankedVendor $c) => $c->vendor->getAttribute('display_name') === 'novato_bom')->isNewVendorSlot
        );
    }

    public function test_returns_everyone_when_fewer_than_the_shortlist_size(): void
    {
        // Regra do negócio: se só dois puderem, mostram-se dois.
        $list = collect([
            $this->candidate('a', 4.9, 40, 3000, 1.0),
            $this->candidate('b', 4.2, 40, 2000, 1.0),
        ]);

        $short = $this->ranking->shortlist($list);

        $this->assertCount(2, $short);
        $this->assertSame([1, 2], $short->map(fn (RankedVendor $c) => $c->rank)->all());
    }

    public function test_ranks_are_sequential_from_one(): void
    {
        $list = collect([
            $this->candidate('c', 3.5, 40, 3000, 1.0),
            $this->candidate('a', 5.0, 40, 3000, 1.0),
            $this->candidate('b', 4.2, 40, 3000, 1.0),
        ]);

        $short = $this->ranking->shortlist($list);

        $this->assertSame(['a', 'b', 'c'], $this->names($short));
        $this->assertSame([1, 2, 3], $short->map(fn (RankedVendor $c) => $c->rank)->all());
    }
}
