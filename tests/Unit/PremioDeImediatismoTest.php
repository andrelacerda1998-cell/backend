<?php

namespace Tests\Unit;

use App\Services\RateService;
use App\Settings\RateSettings;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A formula dos dois modos, escrita como o negocio a define.
 *
 *   AGENDADO   tecnico = valor_hora x tempo
 *              cliente = tecnico / 0,75
 *
 *   IMEDIATO   tecnico = (valor_hora x tempo) / 0,75
 *              cliente = tecnico / 0,75
 *
 * Nos dois a plataforma fica com 25% do que o cliente paga. O que muda no
 * imediato e a BASE: o premio entra no valor do trabalho, por isso o
 * profissional recebe mais 33% e a plataforma ganha mais 33% em euros sem
 * subir a percentagem.
 *
 * O codigo aplicava o premio so do lado do cliente. O profissional recebia
 * por um imediato exatamente o mesmo que por um agendado, e a margem da
 * plataforma nesses pedidos era 43,7% — nao 25%.
 */
class PremioDeImediatismoTest extends TestCase
{
    private RateService $rateService;

    protected function setUp(): void
    {
        parent::setUp();

        // Meio-dia: faixa diurna, multiplicador 1,0, para os numeros do teste
        // serem a formula e nao a formula vezes uma sobretaxa.
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Europe/Lisbon'));

        config(['services.invoiceExpress.vat' => 0]);

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

    public function test_no_agendado_o_tecnico_recebe_o_valor_do_trabalho(): void
    {
        // 10,00 EUR/h durante 1 hora, sem deslocacao.
        $this->assertEqualsWithDelta(
            1000.0,
            $this->rateService->calculateForVendor(1000, 60, 0, false, false),
            0.01,
        );
    }

    public function test_no_imediato_o_tecnico_recebe_o_valor_do_trabalho_com_premio(): void
    {
        $this->assertEqualsWithDelta(
            1000 / 0.75,
            $this->rateService->calculateForVendorInstantService(1000, 60, 0, false, false),
            0.01,
            'o premio e do trabalho, nao da plataforma',
        );
    }

    public function test_o_cliente_paga_o_do_tecnico_a_dividir_por_075_nos_dois_modos(): void
    {
        $tecnicoAgendado = $this->rateService->calculateForVendor(1000, 60, 0, false, false);
        $clienteAgendado = $this->rateService->calculateForCustomerForSchedule(1000, 60, 0, false, false);

        $tecnicoImediato = $this->rateService->calculateForVendorInstantService(1000, 60, 0, false, false);
        $clienteImediato = $this->rateService->calculateForCustomerInstantService(1000, 60, 0, false, false);

        $this->assertEqualsWithDelta($tecnicoAgendado / 0.75, $clienteAgendado, 0.01);
        $this->assertEqualsWithDelta($tecnicoImediato / 0.75, $clienteImediato, 0.01);
    }

    /**
     * O invariante que o fundador declarou: 25% nos dois modos. Era este que
     * falhava — o imediato dava 43,7%.
     */
    public function test_a_plataforma_fica_com_25_porcento_nos_dois_modos(): void
    {
        foreach ([
            'agendado' => [
                $this->rateService->calculateForVendor(1000, 60, 12, false, false),
                $this->rateService->calculateForCustomerForSchedule(1000, 60, 12, false, false),
            ],
            'imediato' => [
                $this->rateService->calculateForVendorInstantService(1000, 60, 12, false, false),
                $this->rateService->calculateForCustomerInstantService(1000, 60, 12, false, false),
            ],
        ] as $modo => [$tecnico, $cliente]) {
            $this->assertEqualsWithDelta(
                25.0,
                (($cliente - $tecnico) / $cliente) * 100,
                0.01,
                "no {$modo} a plataforma devia ficar com 25%",
            );
        }
    }

    /** O cliente nao paga mais nem menos do que ja pagava: o premio so mudou de dono. */
    public function test_o_preco_ao_cliente_nao_muda(): void
    {
        $this->assertEqualsWithDelta(
            (1000 / 0.75) / 0.75,
            $this->rateService->calculateForCustomerInstantService(1000, 60, 0, false, false),
            0.01,
        );
    }

    /** Em euros a plataforma ganha mais no imediato, apesar da percentagem ser a mesma. */
    public function test_a_plataforma_ganha_mais_em_euros_no_imediato(): void
    {
        $lucroAgendado = $this->rateService->calculateForCustomerForSchedule(1000, 60, 0, false, false)
            - $this->rateService->calculateForVendor(1000, 60, 0, false, false);

        $lucroImediato = $this->rateService->calculateForCustomerInstantService(1000, 60, 0, false, false)
            - $this->rateService->calculateForVendorInstantService(1000, 60, 0, false, false);

        $this->assertEqualsWithDelta($lucroAgendado / 0.75, $lucroImediato, 0.01);
        $this->assertGreaterThan($lucroAgendado, $lucroImediato);
    }

    /** A deslocacao tambem leva premio: senao cliente != tecnico/0,75 quando ha km. */
    public function test_a_deslocacao_entra_no_premio(): void
    {
        $tecnico = $this->rateService->calculateForVendorInstantService(0, 0, 10, false, false);
        $cliente = $this->rateService->calculateForCustomerInstantService(0, 0, 10, false, false);

        $this->assertEqualsWithDelta((80 * 10) / 0.75, $tecnico, 0.01);
        $this->assertEqualsWithDelta($tecnico / 0.75, $cliente, 0.01);
    }

    /** O premio aplica-se DEPOIS da faixa horaria, nao em vez dela. */
    public function test_o_premio_acumula_com_a_sobretaxa_horaria(): void
    {
        $noite = Carbon::parse('2026-07-16 22:00', 'Europe/Lisbon')->toImmutable();

        $this->assertEqualsWithDelta(
            (1000 * 1.5) / 0.75,
            $this->rateService->calculateForVendorInstantService(1000, 60, 0, false, false, $noite),
            0.01,
        );
    }
}
