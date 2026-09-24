<?php

namespace Tests\Unit;

use App\Services\RateService;
use App\Settings\RateSettings;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A deslocação que se mostra ao cliente.
 *
 * O preço é uma soma — tempo + quilómetros — que sobe pela comissão da
 * plataforma e pelo IVA. Mostrar `preço_km × km` seria mostrar o que chega ao
 * profissional, não o que sai da carteira de quem paga.
 *
 * O que estes testes prendem é a propriedade que torna a linha honesta no
 * checkout: a deslocação é EXATAMENTE a diferença que os quilómetros fazem ao
 * total. Se um dia a fórmula mudar e esta parcela deixar de fechar, o cliente
 * passa a ver duas linhas que não somam o valor logo abaixo — e isso é pior do
 * que não decompor nada.
 */
class TravelAmountForCustomerTest extends TestCase
{
    private RateService $rateService;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 7, 5, 12, 0, 0));
        config(['services.invoiceExpress.vat' => 23]);

        RateSettings::fake([
            'daytime' => 100,
            'evening' => 120,
            'night' => 150,
            'late_night' => 190,
            'midnight' => 190,
            'kilometer_price' => 80,
            'system_commission' => 25,
        ]);

        $this->rateService = app(RateService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function scenarios(): array
    {
        return [
            'agendado, perto' => [true, 2000, 60, 1],
            'agendado, longe' => [true, 2000, 60, 30],
            'agendado, servico curto' => [true, 3500, 30, 12],
            'imediato, perto' => [false, 2000, 60, 1],
            'imediato, longe' => [false, 2000, 60, 30],
            'imediato, servico longo' => [false, 1500, 180, 7],
        ];
    }

    /**
     * Serviço + deslocação = total. É o que o checkout mostra, e tem de fechar
     * ao cêntimo: o "Serviço" no ecrã sai por subtração deste valor.
     */
    #[DataProvider('scenarios')]
    public function test_a_deslocacao_e_o_que_os_quilometros_acrescentam_ao_total(
        bool $isScheduled,
        int $hourRate,
        int $minutes,
        int $distance,
    ): void {
        $total = $this->customerTotal($isScheduled, $hourRate, $minutes, $distance);
        $semEstrada = $this->customerTotal($isScheduled, $hourRate, $minutes, 0);
        $travel = $this->rateService->calculateTravelForCustomer($distance, $isScheduled);

        $this->assertEqualsWithDelta($total - $semEstrada, $travel, 1.0);
        $this->assertGreaterThan(0, $travel);
        $this->assertLessThan($total, $travel);
    }

    /**
     * A parcela do trabalho não pode depender da distância: se dependesse, a
     * linha "Serviço" mudava de valor consoante a morada do profissional e
     * deixava de explicar coisa nenhuma.
     */
    public function test_a_parcela_do_servico_nao_muda_com_a_distancia(): void
    {
        $perto = $this->customerTotal(true, 2000, 60, 1)
            - $this->rateService->calculateTravelForCustomer(1, true);
        $longe = $this->customerTotal(true, 2000, 60, 30)
            - $this->rateService->calculateTravelForCustomer(30, true);

        $this->assertEqualsWithDelta($perto, $longe, 1.0);
    }

    /**
     * A deslocação NÃO leva a comissão horária: essa multiplica o tempo de
     * trabalho, não a estrada. Sem isto, um serviço de madrugada mostraria uma
     * deslocação inflacionada que não corresponde a nada no total.
     */
    public function test_a_deslocacao_nao_muda_com_a_hora_do_dia(): void
    {
        $dia = $this->rateService->calculateTravelForCustomer(10, true);

        Carbon::setTestNow(Carbon::create(2026, 7, 5, 4, 0, 0)); // madrugada, 190%
        $madrugada = app(RateService::class)->calculateTravelForCustomer(10, true);

        $this->assertSame($dia, $madrugada);
    }

    /**
     * A deslocação é a MESMA nos dois modos.
     *
     * Este teste afirmava o contrário — que a estrada acompanhava o prémio de
     * imediatismo — e prendia o comportamento até 24/09/2026. A razão que dava
     * era que "não fica congelada enquanto o resto sobe"; só que a estrada é a
     * mesma estrada. Os mesmos quilómetros, o mesmo combustível, quer o pedido
     * seja para agora ou para quinta às 10:00.
     *
     * É o mesmo raciocínio que o teste acima já aplicava à faixa horária: se a
     * deslocação não custa mais às 03:00, também não custa mais por ser agora.
     * O prémio paga o trabalho de largar tudo, não a viagem.
     */
    public function test_a_deslocacao_e_igual_no_imediato_e_no_agendado(): void
    {
        $this->assertSame(
            $this->rateService->calculateTravelForCustomer(10, true),
            $this->rateService->calculateTravelForCustomer(10, false),
        );
    }

    /**
     * E o prémio continua a existir — no trabalho.
     *
     * Sem isto, alguém podia "corrigir" o teste acima tirando o prémio de todo
     * e os testes continuavam verdes com os imediatos a valer o mesmo que os
     * agendados.
     */
    public function test_o_premio_de_imediatismo_continua_a_valer_sobre_o_trabalho(): void
    {
        $this->assertGreaterThan(
            $this->customerTotal(true, 2000, 90, 0),
            $this->customerTotal(false, 2000, 90, 0),
        );
    }

    private function customerTotal(bool $isScheduled, int $hourRate, int $minutes, int $distance): float
    {
        return $isScheduled
            ? $this->rateService->calculateForCustomerForSchedule($hourRate, $minutes, $distance)
            : $this->rateService->calculateForCustomerInstantService($hourRate, $minutes, $distance);
    }
}
