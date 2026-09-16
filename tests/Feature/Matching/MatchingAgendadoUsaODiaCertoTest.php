<?php

namespace Tests\Feature\Matching;

use App\Models\Service;
use App\Services\Matching\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O agendamento em selecao tem de ser verificado NO DIA CERTO.
 *
 * Enquanto o cliente ainda nao pagou, o dia e a hora vivem em
 * `pending_schedule_data` — nao ha linha em `schedule`, porque essa exige um
 * vendor_id que ainda nao existe. O `scheduledStartAt()` lia dali so a HORA e
 * fazia `Carbon::parse('15:00')`, que devolve HOJE as 15:00.
 *
 * O efeito, apanhado a percorrer o fluxo a serio em 16/09/2026: um pedido para
 * o dia seguinte as 15:00 era avaliado contra a agenda de HOJE as 15:00. O
 * unico profissional elegivel tinha um servico a essa hora hoje, ficou de fora,
 * e o pedido caiu em MatchingFailed — o cliente nao recebeu proposta nenhuma,
 * sem nada no log que explicasse porque.
 */
class MatchingAgendadoUsaODiaCertoTest extends TestCase
{
    use RefreshDatabase;

    private function servicoComIntencao(array $schedule): Service
    {
        $service = Service::factory()->create();
        $service->forceFill(['pending_schedule_data' => [
            'scheduled' => true,
            'schedule' => $schedule,
        ]])->save();

        return $service->refresh();
    }

    public function test_o_dia_e_a_hora_vem_os_dois_da_intencao(): void
    {
        $service = $this->servicoComIntencao([
            'scheduled_day' => '2026-12-24',
            'scheduled_time_start' => '15:00',
        ]);

        $inicio = app(MatchingService::class)->scheduledStartAt($service);

        $this->assertNotNull($inicio);
        $this->assertSame('2026-12-24 15:00:00', $inicio->format('Y-m-d H:i:s'));
    }

    public function test_sem_hora_fica_o_dia_a_meia_noite_e_nao_o_dia_de_hoje(): void
    {
        // Melhor o dia certo a uma hora aproximada do que o dia errado a hora
        // certa: o que decide quem e convidado e a agenda DAQUELE dia.
        $service = $this->servicoComIntencao(['scheduled_day' => '2026-12-24']);

        $inicio = app(MatchingService::class)->scheduledStartAt($service);

        $this->assertSame('2026-12-24 00:00:00', $inicio?->format('Y-m-d H:i:s'));
    }

    public function test_sem_dia_nao_se_inventa_uma_data(): void
    {
        // So com hora nao da para saber de que dia se fala. Devolver hoje era
        // exatamente o bug; devolver null deixa o chamador tratar como
        // "pedido imediato", que e o que de facto e.
        $service = $this->servicoComIntencao(['scheduled_time_start' => '15:00']);

        $this->assertNull(app(MatchingService::class)->scheduledStartAt($service));
    }

    public function test_aceita_tambem_o_formato_com_data_completa(): void
    {
        // `scheduled_time_start` nao tem formato unico: a app manda "15:00",
        // mas ha caminhos que gravam o datetime inteiro. Concatenar as cegas
        // dava "2026-12-24 2026-12-24 10:00:00", que o Carbon recusa — e foi
        // isso que partiu o MatchingAdvanceTest na primeira tentativa.
        $service = $this->servicoComIntencao([
            'scheduled_day' => '2026-12-24',
            'scheduled_time_start' => '2026-12-24 10:00:00',
        ]);

        $inicio = app(MatchingService::class)->scheduledStartAt($service);

        $this->assertSame('2026-12-24 10:00:00', $inicio?->format('Y-m-d H:i:s'));
    }

    public function test_com_agendamento_ja_materializado_manda_a_linha_do_schedule(): void
    {
        $service = Service::factory()->create();
        $service->schedule()->create([
            'vendor_id' => $service->vendor_id,
            'customer_id' => $service->customer_id,
            'service_type_id' => $service->services_type_id,
            'scheduled_day' => '2026-11-03',
            'scheduled_time_start' => '09:30:00',
            'scheduled_time_end' => '10:30:00',
            'is_pending' => false,
        ]);

        $inicio = app(MatchingService::class)->scheduledStartAt($service->refresh());

        $this->assertSame('2026-11-03 09:30:00', $inicio?->format('Y-m-d H:i:s'));
    }
}
