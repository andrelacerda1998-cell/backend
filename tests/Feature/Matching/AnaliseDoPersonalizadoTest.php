<?php

namespace Tests\Feature\Matching;

use App\Console\Commands\AdvanceMatchingCommand;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Admin\CustomRequestStuckNotification;
use App\Notifications\Customer\MatchingFailedNotification;
use App\Settings\MatchingSettings;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Um pedido personalizado em análise deixa de ter fundo.
 *
 * Nasce em PendingReview e só sai de lá quando alguém no backoffice lhe define
 * a duração e as áreas. Antes disto NADA o expirava: ficava vivo para sempre,
 * o cliente a olhar para "Pedido em análise", e ninguém era avisado de nada —
 * nem ele, nem nós.
 */
class AnaliseDoPersonalizadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();

        MatchingSettings::fake([
            'custom_review_alert_weekdays' => 1,
            'custom_review_deadline_weekdays' => 2,
        ]);
    }

    /** Um personalizado em análise, criado há N dias úteis. */
    private function emAnalise(int $diasUteisAtras): Service
    {
        return Service::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'services_type_id' => null,
            'vendor_id' => null,
            'is_custom' => true,
            'custom_description' => 'Trocar a fechadura da porta da rua, que ficou empenada.',
            'status' => ServiceStatus::PENDING_REVIEW,
            'created_at' => now()->subWeekdays($diasUteisAtras),
        ]);
    }

    private function correrVarredura(): void
    {
        $this->artisan(AdvanceMatchingCommand::class);
    }

    public function test_um_pedido_acabado_de_fazer_fica_quieto(): void
    {
        $service = $this->emAnalise(0);

        $this->correrVarredura();

        $this->assertSame(ServiceStatus::PENDING_REVIEW, $service->refresh()->status);
        $this->assertNull($service->custom_review_alerted_at, 'ninguém tem de ser incomodado no primeiro dia');
        Notification::assertNothingSent();
    }

    public function test_ao_primeiro_dia_util_o_backoffice_e_avisado(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service = $this->emAnalise(1);

        $this->correrVarredura();

        Notification::assertSentTo($admin, CustomRequestStuckNotification::class);
        // Ainda em análise: o aviso serve para alguém AGIR, não para desistir.
        $this->assertSame(ServiceStatus::PENDING_REVIEW, $service->refresh()->status);
        $this->assertNotNull($service->custom_review_alerted_at);
    }

    public function test_o_aviso_sai_uma_vez_e_nao_a_cada_minuto(): void
    {
        // O comando corre de minuto a minuto. Sem o carimbo, um pedido
        // esquecido enchia a caixa de correio de quem o devia despachar — e um
        // aviso repetido ensina-se a ignorar, que é o oposto do que se quer.
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->emAnalise(1);

        $this->correrVarredura();
        $this->correrVarredura();
        $this->correrVarredura();

        Notification::assertSentToTimes($admin, CustomRequestStuckNotification::class, 1);
    }

    public function test_ao_segundo_dia_util_o_pedido_falha_e_o_cliente_sabe(): void
    {
        $service = $this->emAnalise(2);

        $this->correrVarredura();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
        Notification::assertSentTo($service->customer, MatchingFailedNotification::class);
    }

    public function test_o_fim_de_semana_nao_conta(): void
    {
        // Um pedido feito à sexta à noite não pode morrer no domingo, quando
        // ninguém teve oportunidade de lhe pegar. É por isso que o prazo é em
        // dias úteis e não em dias corridos.
        $this->travelTo(now()->startOfWeek()->addDays(4)->setTime(18, 0)); // sexta
        $service = $this->emAnalise(0);

        $this->travelTo(now()->addDays(2)->setTime(10, 0)); // domingo
        $this->correrVarredura();

        $this->assertSame(
            ServiceStatus::PENDING_REVIEW,
            $service->refresh()->status,
            'dois dias de calendário passaram, mas nenhum foi dia útil',
        );
    }

    public function test_um_pedido_de_catalogo_nao_e_tocado(): void
    {
        // A varredura é só dos personalizados: um de catálogo nunca passa por
        // PendingReview, e apanhar tudo aqui seria mexer noutro fluxo.
        $service = Service::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'services_type_id' => ServicesType::factory(),
            'is_custom' => false,
            'status' => ServiceStatus::PENDING_REVIEW,
            'created_at' => now()->subWeekdays(5),
        ]);

        $this->correrVarredura();

        $this->assertSame(ServiceStatus::PENDING_REVIEW, $service->refresh()->status);
    }
}
