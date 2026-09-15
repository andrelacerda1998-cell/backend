<?php

namespace Tests\Feature\Matching;

use App\Enums\Schedule\ScheduleDay;
use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\ScheduleAvailable;
use App\Models\Schedule\ScheduleDays;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O horario semanal declarado deixou de decidir quem e convidado.
 *
 * O que se prova aqui e a fronteira: preferencia antiga (horario) nao veta;
 * facto (ferias, sobreposicao) continua a vetar.
 */
class DisponibilidadeNaoVetaConvitesTest extends TestCase
{
    use RefreshDatabase;

    private function proximaSegunda(): Carbon
    {
        return Carbon::now()->next(Carbon::MONDAY)->setTime(10, 0);
    }

    private function proximoSabado(): Carbon
    {
        return Carbon::now()->next(Carbon::SATURDAY)->setTime(10, 0);
    }

    private function comHorario(Vendor $vendor, string $dia, string $inicio, string $fim, bool $ativo): void
    {
        $day = ScheduleDays::firstOrCreate(['day_name' => $dia]);

        ScheduleAvailable::updateOrCreate(
            ['vendor_id' => $vendor->id, 'day_id' => $day->id],
            ['time_start' => $inicio, 'time_end' => $fim, 'is_enabled' => $ativo, 'auto_accept' => false],
        );
    }

    public function test_sabado_desligado_deixa_de_impedir_o_convite(): void
    {
        $vendor = Vendor::factory()->create(['status' => StatusVendor::ONLINE]);
        $this->comHorario($vendor, ScheduleDay::SATURDAY->value, '08:00:00', '19:00:00', false);

        $sabado = $this->proximoSabado();

        // Antes de 15/09/2026 isto era false e o profissional nem era notificado.
        $this->assertTrue($vendor->hasFreeSlot($sabado, $sabado->copy()->addHour()));
    }

    public function test_hora_fora_do_horario_declarado_deixa_de_impedir_o_convite(): void
    {
        $vendor = Vendor::factory()->create(['status' => StatusVendor::ONLINE]);
        $this->comHorario($vendor, ScheduleDay::MONDAY->value, '08:00:00', '19:00:00', true);

        $madrugada = $this->proximaSegunda()->setTime(3, 0);

        $this->assertTrue($vendor->hasFreeSlot($madrugada, $madrugada->copy()->addHour()));
    }

    public function test_sem_horario_nenhum_declarado_continua_a_ser_convidavel(): void
    {
        $vendor = Vendor::factory()->create(['status' => StatusVendor::ONLINE]);

        $segunda = $this->proximaSegunda();

        $this->assertTrue($vendor->hasFreeSlot($segunda, $segunda->copy()->addHour()));
    }

    public function test_dia_marcado_como_indisponivel_continua_a_vetar(): void
    {
        $vendor = Vendor::factory()->create(['status' => StatusVendor::ONLINE]);
        $segunda = $this->proximaSegunda();

        $vendor->unavailableDays()->create(['day' => $segunda->toDateString()]);

        // Férias são um facto, não uma previsão: continuam a mandar.
        $this->assertFalse($vendor->hasFreeSlot($segunda, $segunda->copy()->addHour()));
    }

    public function test_sobreposicao_com_servico_marcado_continua_a_vetar(): void
    {
        $vendor = Vendor::factory()->create(['status' => StatusVendor::ONLINE]);
        $segunda = $this->proximaSegunda();

        $vendor->schedules()->create([
            'customer_id' => User::factory()->create()->id,
            'service_type_id' => ServicesType::factory()->create()->id,
            'scheduled_day' => $segunda->toDateString(),
            'scheduled_time_start' => '10:00:00',
            'scheduled_time_end' => '11:00:00',
            'is_pending' => false,
        ]);

        // Ninguém está em dois sítios às 10h30.
        $sobreposto = $segunda->copy()->setTime(10, 30);
        $this->assertFalse($vendor->hasFreeSlot($sobreposto, $sobreposto->copy()->addHour()));
    }
}
