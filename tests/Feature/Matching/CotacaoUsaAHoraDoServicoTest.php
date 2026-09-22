<?php

namespace Tests\Feature\Matching;

use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Services\Matching\MatchingScope;
use App\Settings\RateSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A hora do servico tem de chegar ate onde o preco e feito.
 *
 * O `RateService` ja sabe receber a hora, mas isso so vale se alguem lha der.
 * Este teste percorre a ligacao que interessa — servico -> `scheduledAt()` ->
 * `MatchingScope` -> cotacao — em vez de confiar que os parametros foram
 * ligados. Foi a cotacao do convite que ficou congelada no candidato, e e
 * essa que o cliente acaba por pagar.
 */
class CotacaoUsaAHoraDoServicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateSettings::fake([
            'daytime' => 100,
            'evening' => 120,
            'night' => 150,
            'late_night' => 190,
            'midnight' => 190,
            'kilometer_price' => 80,
            'system_commission' => 25,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servicoComIntencao(array $schedule): Service
    {
        $service = Service::factory()->create();
        $service->forceFill(['pending_schedule_data' => [
            'scheduled' => true,
            'schedule' => $schedule,
        ]])->save();

        return $service->refresh();
    }

    public function test_o_scope_leva_a_hora_do_servico_e_nao_a_de_agora(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 23:00:00', 'Europe/Lisbon'));

        $service = $this->servicoComIntencao([
            'scheduled_day' => '2026-07-16',
            'scheduled_time_start' => '10:00',
        ]);

        $scope = MatchingScope::forService($service);

        $this->assertNotNull($scope->serviceAt, 'sem isto o ranking cota com o relogio da parede');
        $this->assertSame('2026-07-16 10:00', $scope->serviceAt->format('Y-m-d H:i'));
    }

    public function test_um_pedido_imediato_nao_leva_hora(): void
    {
        $scope = MatchingScope::forService(Service::factory()->create());

        $this->assertNull($scope->serviceAt, 'imediato quer dizer agora, e agora e o default do RateService');
    }

    public function test_tambem_le_a_agenda_ja_materializada(): void
    {
        $service = Service::factory()->create();

        Schedule::create([
            'service_id' => $service->id,
            'vendor_id' => $service->vendor_id,
            'customer_id' => $service->customer_id,
            'service_type_id' => $service->services_type_id,
            'scheduled_day' => '2026-07-16',
            'scheduled_time_start' => '21:30',
            'scheduled_time_end' => '22:30',
        ]);

        $this->assertSame('2026-07-16 21:30', $service->refresh()->scheduledAt()?->format('Y-m-d H:i'));
    }

    /**
     * O formato da hora nao e unico: a app manda "21:30", ha caminhos que
     * gravam o datetime inteiro. Concatenar dava "2026-07-16 2026-07-16 21:30".
     */
    public function test_aceita_a_hora_em_datetime_completo(): void
    {
        $service = $this->servicoComIntencao([
            'scheduled_day' => '2026-07-16',
            'scheduled_time_start' => '2026-07-16 21:30:00',
        ]);

        $this->assertSame('2026-07-16 21:30', $service->scheduledAt()?->format('Y-m-d H:i'));
    }

    /**
     * Sem hora nao se assume meia-noite: seria a faixa da madrugada (x1,90) e
     * um agravamento de 90% saido de um campo em falta. Null = "agora".
     */
    public function test_sem_hora_nao_inventa_meia_noite(): void
    {
        $service = $this->servicoComIntencao(['scheduled_day' => '2026-07-16']);

        $this->assertNull($service->scheduledAt());
    }

    public function test_uma_data_invalida_nao_rebenta_o_checkout(): void
    {
        $service = $this->servicoComIntencao([
            'scheduled_day' => 'nao-e-uma-data',
            'scheduled_time_start' => '10:00',
        ]);

        $this->assertNull($service->scheduledAt());
    }
}
