<?php

namespace Tests\Feature\Services;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A auto-aceitação nasce desligada.
 *
 * Com o fluxo de seleção, tê-la ligada significa responder que sim a todos os
 * pedidos de serviço em nome do profissional — uma escolha que tem de ser dele.
 * Nascer ligada fazia toda a gente aceitar tudo sem nunca o ter decidido, e a
 * etapa de resposta perdia sentido.
 */
class AutoAcceptDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_vendor_does_not_auto_accept(): void
    {
        $vendor = Vendor::factory()->create();

        $this->assertFalse($vendor->autoAcceptsOn());
        $this->assertSame(0, $vendor->scheduleAvailable()->where('auto_accept', true)->count());
    }

    public function test_the_weekly_blocks_are_still_created(): void
    {
        // Desligar a auto-aceitação não pode ter apagado a disponibilidade:
        // sem ela o profissional deixaria de aparecer em qualquer pesquisa.
        $vendor = Vendor::factory()->create();

        $this->assertSame(7, $vendor->scheduleAvailable()->count());
        $this->assertSame(5, $vendor->scheduleAvailable()->where('is_enabled', true)->count());
    }

    public function test_turning_it_on_works(): void
    {
        $vendor = Vendor::factory()->create();
        $vendor->scheduleAvailable()->update(['auto_accept' => true]);

        $this->assertTrue($vendor->fresh()->autoAcceptsOn());
    }

    /**
     * Regressão do incidente 13/08: com uma data, a auto-aceitação tem de olhar
     * para o bloco DESSE dia da semana. O fim-de-semana nasce desligado
     * (VendorObserver), por isso nem com a auto-aceitação ligada em todos os
     * blocos um agendamento de sábado pode ser aceite sozinho — tem de cair no
     * caminho manual. É o que os quatro pontos do fluxo clássico passaram a usar.
     */
    public function test_auto_accept_respects_the_weekday(): void
    {
        $vendor = Vendor::factory()->create();
        $vendor->scheduleAvailable()->update(['auto_accept' => true]);
        $vendor = $vendor->fresh();

        $weekday = now()->next(\Carbon\Carbon::MONDAY);
        $weekend = now()->next(\Carbon\Carbon::SATURDAY);

        $this->assertTrue(
            $vendor->autoAcceptsOn($weekday),
            'dia útil (segunda) está ligado — deve auto-aceitar',
        );
        $this->assertFalse(
            $vendor->autoAcceptsOn($weekend),
            'sábado nasce desligado — não pode auto-aceitar',
        );
    }
}
