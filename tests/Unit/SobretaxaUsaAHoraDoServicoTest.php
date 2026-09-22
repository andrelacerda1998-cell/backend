<?php

namespace Tests\Unit;

use App\Services\RateService;
use App\Settings\RateSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A sobretaxa horaria e do SERVICO, e le-se em Lisboa.
 *
 * Dois defeitos viviam na mesma linha do `calculateHourCommission`:
 *
 *  1. Lia `Carbon::now()` — a hora em que alguem pediu a conta — e nao a hora
 *     a que o trabalho ia ser feito. Um cliente que marcasse as 22:00 um
 *     servico para as 10:00 do dia seguinte pagava a faixa da noite, e o mesmo
 *     trabalho custava valores diferentes conforme a hora do checkout.
 *
 *  2. Lia essa hora em UTC. O APP_TIMEZONE e UTC e Portugal esta em UTC+1 de
 *     final de marco a final de outubro, por isso no verao a tabela inteira
 *     andava uma hora: 08:00 de Lisboa eram lidas como 07:00 e cobradas a
 *     faixa da madrugada (x1,90) em vez da diurna (x1,00).
 */
class SobretaxaUsaAHoraDoServicoTest extends TestCase
{
    private RateService $rateService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.invoiceExpress.vat' => 23]);

        // Os valores reais de producao, para os multiplicadores do teste
        // serem os que o cliente paga e nao numeros inventados.
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

    /** Preco de mao de obra pura: 1 hora, sem deslocacao, sem IVA. */
    private function maoDeObra(?CarbonImmutable $quando): float
    {
        return $this->rateService->calculateForVendor(1000, 60, 0, false, false, $quando);
    }

    public static function faixas(): array
    {
        //        rotulo            hora Lisboa  multiplicador
        return [
            'diurno 08h' => ['08:00', 1.00],
            'diurno 17h' => ['17:00', 1.00],
            'tarde 18h' => ['18:00', 1.20],
            'tarde 20h' => ['20:00', 1.20],
            'noite 21h' => ['21:00', 1.50],
            'noite 23h' => ['23:00', 1.50],
            'madrugada 00h' => ['00:00', 1.90],
            'madrugada 02h' => ['02:00', 1.90],
            'madrugada 03h' => ['03:00', 1.90],
            'madrugada 07h' => ['07:00', 1.90],
        ];
    }

    #[DataProvider('faixas')]
    public function test_a_faixa_e_a_da_hora_do_servico(string $hora, float $multiplicador): void
    {
        // Calculado numa hora deliberadamente errada: meio da madrugada.
        Carbon::setTestNow(Carbon::parse('2026-07-15 01:00:00', 'Europe/Lisbon'));

        $quando = CarbonImmutable::parse("2026-07-16 {$hora}", 'Europe/Lisbon');

        $this->assertEqualsWithDelta(
            1000 * $multiplicador,
            $this->maoDeObra($quando),
            0.01,
            "Servico as {$hora} devia custar x{$multiplicador}, seja qual for a hora do checkout.",
        );
    }

    public function test_o_preco_nao_muda_com_a_hora_do_checkout(): void
    {
        $servico = CarbonImmutable::parse('2026-07-16 10:00', 'Europe/Lisbon');

        Carbon::setTestNow(Carbon::parse('2026-07-15 22:00:00', 'Europe/Lisbon'));
        $pagouANoite = $this->maoDeObra($servico);

        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Europe/Lisbon'));
        $pagouDeDia = $this->maoDeObra($servico);

        $this->assertSame(
            $pagouDeDia,
            $pagouANoite,
            'O mesmo servico agendado nao pode ter dois precos por o cliente ter carregado no botao a horas diferentes.',
        );
    }

    /**
     * O caso que estava a cobrar a mais em producao: verao, servico de manha.
     *
     * 08:00 em Lisboa sao 07:00 UTC. A ler em UTC caia em `midnight` (x1,90);
     * a ler em Lisboa cai em `daytime` (x1,00) — 90% de diferenca.
     */
    public function test_horario_de_verao_nao_desvia_a_faixa(): void
    {
        // "Agora" fica fora da faixa diurna de proposito: se o preco voltasse
        // a ler o relogio do checkout, este teste passava por acaso.
        Carbon::setTestNow(Carbon::parse('2026-07-15 01:00:00', 'Europe/Lisbon'));

        $manhaDeVerao = CarbonImmutable::parse('2026-07-16 08:00', 'Europe/Lisbon');

        $this->assertSame(1, $manhaDeVerao->utcOffset() / 60, 'Julho em Lisboa e UTC+1; sem isso o teste nao prova nada.');

        $this->assertEqualsWithDelta(1000.0, $this->maoDeObra($manhaDeVerao), 0.01);
    }

    /** No inverno Lisboa e UTC+0 e o resultado tem de ser o mesmo. */
    public function test_horario_de_inverno_da_a_mesma_faixa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 01:00:00', 'Europe/Lisbon'));

        $manhaDeInverno = CarbonImmutable::parse('2026-01-16 08:00', 'Europe/Lisbon');

        $this->assertSame(0, $manhaDeInverno->utcOffset() / 60);

        $this->assertEqualsWithDelta(1000.0, $this->maoDeObra($manhaDeInverno), 0.01);
    }

    /** A mesma hora escrita noutro fuso e o mesmo instante, logo o mesmo preco. */
    public function test_o_fuso_de_entrada_e_indiferente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Europe/Lisbon'));

        $emLisboa = CarbonImmutable::parse('2026-07-16 22:00', 'Europe/Lisbon');
        $oMesmoInstanteEmUtc = $emLisboa->setTimezone('UTC');

        $this->assertSame($this->maoDeObra($emLisboa), $this->maoDeObra($oMesmoInstanteEmUtc));

        // E o instante tem de cair mesmo na faixa da noite (x1,50). Sem isto o
        // teste ficava satisfeito com dois valores iguais e ambos errados.
        $this->assertEqualsWithDelta(1500.0, $this->maoDeObra($emLisboa), 0.01);
    }

    /** Sem hora indicada e um imediato: vale "agora", tambem lido em Lisboa. */
    public function test_sem_hora_usa_agora_em_lisboa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 08:30:00', 'Europe/Lisbon'));

        $this->assertEqualsWithDelta(
            1000.0,
            $this->maoDeObra(null),
            0.01,
            'Um imediato as 08:30 de Lisboa e diurno, nao madrugada.',
        );
    }

    /**
     * A deslocacao nao tem faixa horaria: a estrada custa o mesmo a qualquer
     * hora. Fixa-se aqui porque `calculateTravelForCustomer` nao recebe hora
     * nenhuma, e sem este teste isso parece um esquecimento.
     */
    public function test_a_deslocacao_nao_depende_da_hora(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Europe/Lisbon'));
        $aoMeioDia = $this->rateService->calculateTravelForCustomer(10, true);

        Carbon::setTestNow(Carbon::parse('2026-07-15 02:00:00', 'Europe/Lisbon'));
        $deMadrugada = $this->rateService->calculateTravelForCustomer(10, true);

        $this->assertSame($aoMeioDia, $deMadrugada);
    }
}
