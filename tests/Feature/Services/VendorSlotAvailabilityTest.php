<?php

namespace Tests\Feature\Services;

use App\Models\Schedule\ScheduleAvailable;
use App\Models\Schedule\Schedule;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O convite só sai a quem tem o bloco livre.
 *
 * Convidar alguém para uma hora que não tem disponível é pior do que não o
 * convidar: ou recusa — e aprende que os convites não são de fiar — ou aceita
 * por distração e falta, o que custa ao cliente e à reputação da Piquet.
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
        $service = \App\Models\Service::factory()->create(['vendor_id' => $this->vendor->id]);

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

    public function test_slot_before_working_hours_is_refused(): void
    {
        [$start, $end] = $this->slot('07:00');

        $this->assertFalse($this->vendor->hasFreeSlot($start, $end));
    }

    public function test_slot_that_spills_past_closing_time_is_refused(): void
    {
        // Começa dentro do horário mas acaba depois: o bloco tem de caber
        // INTEIRO, senão o serviço arrasta-se para lá do que ele definiu.
        [$start, $end] = $this->slot('17:30', 60);

        $this->assertFalse($this->vendor->hasFreeSlot($start, $end));
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

    public function test_a_day_he_does_not_work_is_refused(): void
    {
        // Domingo: o observer cria-o desativado por omissão, que é exatamente
        // o caso de "não trabalho neste dia".
        $sunday = $this->tuesday->copy()->next(\Carbon\Carbon::SUNDAY)->setTimeFromTimeString('10:00');

        $this->assertFalse($this->vendor->hasFreeSlot($sunday, $sunday->copy()->addHour()));
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

    public function test_disabled_weekly_availability_is_refused(): void
    {
        ScheduleAvailable::where('vendor_id', $this->vendor->id)
            ->where('day_id', 2)
            ->update(['is_enabled' => false]);
        [$start, $end] = $this->slot('10:00');

        $this->assertFalse($this->vendor->fresh()->hasFreeSlot($start, $end));
    }
}
