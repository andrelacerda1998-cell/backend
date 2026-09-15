<?php

namespace Tests\Feature\Services;

use App\Models\Schedule\Schedule;
use App\Models\Schedule\ScheduleAvailable;
use App\Models\Service;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O convite só sai a quem pode MESMO lá estar.
 *
 * Duas coisas vetam: férias marcadas, e já ter outro serviço à mesma hora.
 * Ninguém está em dois sítios ao mesmo tempo — isso não é preferência.
 *
 * O horário semanal declarado deixou de vetar a 15/09/2026: era uma previsão
 * feita uma vez no registo a decidir por cima de um convite concreto, que traz
 * serviço, valor, morada e hora. Quem não quiser aquele trabalho recusa. Os
 * quatro testes que provavam o veto do horário mudaram de casa, e de sinal,
 * para DisponibilidadeNaoVetaConvitesTest.
 */
class VendorSlotAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    /** Terça-feira, para casar com a disponibilidade semanal dos testes. */
    private Carbon $tuesday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->tuesday = Carbon::parse('next tuesday')->setTime(0, 0);

        // O VendorObserver já cria os 7 blocos semanais ao criar o profissional
        // (dias úteis ativos, fim de semana desativado). Ajustamos o de terça em
        // vez de criar um oitavo — criar outro fazia a consulta apanhar o do
        // observer e o teste media outra coisa que não o que dizia medir.
        ScheduleAvailable::where('vendor_id', $this->vendor->id)
            ->where('day_id', 2)
            ->update(['time_start' => '09:00:00', 'time_end' => '18:00:00', 'is_enabled' => true]);
    }

    private function slot(string $time, int $minutes = 60): array
    {
        $start = $this->tuesday->copy()->setTimeFromTimeString($time);

        return [$start, $start->copy()->addMinutes($minutes)];
    }

    private function book(string $time, int $minutes = 60, bool $pending = false): Schedule
    {
        $start = $this->tuesday->copy()->setTimeFromTimeString($time);

        // schedule.service_id é NOT NULL: uma marcação existe sempre por causa
        // de um serviço.
        $service = Service::factory()->create(['vendor_id' => $this->vendor->id]);

        return Schedule::create([
            'vendor_id' => $this->vendor->id,
            'customer_id' => $service->customer_id,
            'service_id' => $service->id,
            'service_type_id' => $service->services_type_id,
            'scheduled_day' => $this->tuesday->toDateString(),
            'scheduled_time_start' => $start->format('H:i:s'),
            'scheduled_time_end' => $start->copy()->addMinutes($minutes)->format('H:i:s'),
            'is_pending' => $pending,
        ]);
    }

    public function test_free_slot_inside_working_hours(): void
    {
        [$start, $end] = $this->slot('10:00');

        $this->assertTrue($this->vendor->hasFreeSlot($start, $end));
    }

    public function test_day_off_beats_weekly_availability(): void
    {
        DB::table('vendor_unavailable_days')->insert([
            'vendor_id' => $this->vendor->id,
            'day' => $this->tuesday->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$start, $end] = $this->slot('10:00');

        $this->assertFalse($this->vendor->fresh()->hasFreeSlot($start, $end));
    }

    public function test_overlapping_booking_blocks_the_slot(): void
    {
        $this->book('10:00', 60);
        [$start, $end] = $this->slot('10:30');

        $this->assertFalse($this->vendor->fresh()->hasFreeSlot($start, $end));
    }

    public function test_confirmed_booking_reserves_travel_margin_after_it(): void
    {
        // Margem de segurança: acaba às 11:00, mas ainda precisa de tempo para
        // se deslocar. Um serviço às 11:30 não cabe.
        config(['services.request.schedule_safety_margin_minutes' => 60]);
        $this->book('10:00', 60, pending: false);
        [$start, $end] = $this->slot('11:30');

        $this->assertFalse($this->vendor->fresh()->hasFreeSlot($start, $end));
    }

    public function test_pending_booking_does_not_reserve_the_margin(): void
    {
        // Um agendamento ainda por confirmar não deve reservar tempo de
        // deslocação que talvez nunca seja preciso.
        config(['services.request.schedule_safety_margin_minutes' => 60]);
        $this->book('10:00', 60, pending: true);
        [$start, $end] = $this->slot('11:30');

        $this->assertTrue($this->vendor->fresh()->hasFreeSlot($start, $end));
    }

    public function test_booking_on_another_day_does_not_interfere(): void
    {
        $this->book('10:00', 60);
        $nextTuesday = $this->tuesday->copy()->addWeek()->setTimeFromTimeString('10:00');

        $this->assertTrue($this->vendor->fresh()->hasFreeSlot($nextTuesday, $nextTuesday->copy()->addHour()));
    }
}
