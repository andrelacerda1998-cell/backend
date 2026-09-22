<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Api\Customer\Services\OpenServiceController;
use App\Http\Requests\Api\Customer\Services\OpenServiceRequest;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\MaterializePendingSchedule;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Quanto tempo dura este trabalho — uma pergunta, uma resposta.
 *
 * A quantidade que o cliente escolhe ("2 torneiras na mesma visita") entrava só
 * no preço e no filtro de elegibilidade. Não entrava na duração da marcação,
 * nem no convite ao técnico: o cliente pagava três horas e a agenda bloqueava
 * uma, o convite dizia "1 hora" para um trabalho de três, e o cronómetro dava
 * "tempo excedido" ao minuto 60 — com atalho para pedir ao cliente que pagasse
 * horas que já tinha comprado.
 *
 * Service::durationMinutes() passa a ser a única resposta. Os consumidores que
 * liam `serviceType->time` em cru passam por aqui.
 */
class DuracaoDoTrabalhoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GenderSeeder::class);
        Event::fake();
        Notification::fake();
        Queue::fake();
    }

    private function servico(array $extra = [], int $tempoDoTipo = 60): Service
    {
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $serviceType = ServicesType::factory()->create(['time' => $tempoDoTipo]);

        $service = Service::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $serviceType->id,
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
        ], $extra));

        return $service->refresh();
    }

    // --- a conta ----------------------------------------------------------

    public function test_duas_unidades_de_uma_hora_sao_duas_horas(): void
    {
        $service = $this->servico(['quantity' => 2], tempoDoTipo: 60);

        $this->assertSame(120, $service->durationMinutes());
    }

    public function test_uma_unidade_continua_a_ser_o_tempo_do_tipo(): void
    {
        $service = $this->servico(['quantity' => 1], tempoDoTipo: 90);

        $this->assertSame(90, $service->durationMinutes());
    }

    public function test_sem_quantidade_conta_se_uma(): void
    {
        // A coluna não aceita null, mas o método tem de aguentar um serviço
        // carregado sem ela — é o que acontece num `select` parcial.
        $service = $this->servico(['quantity' => 1], tempoDoTipo: 45);
        $service->quantity = null;

        $this->assertSame(45, $service->durationMinutes());
    }

    public function test_num_personalizado_manda_a_duracao_do_backoffice(): void
    {
        $service = $this->servico(['services_type_id' => null]);
        $service->forceFill(['is_custom' => true, 'custom_duration_minutes' => 150, 'quantity' => 3])->save();

        // Num personalizado não há unidades a multiplicar: o backoffice olhou
        // para o pedido e disse quanto tempo leva, ponto.
        $this->assertSame(150, $service->fresh()->durationMinutes());
    }

    public function test_quando_nao_se_sabe_devolve_nulo_em_vez_de_inventar(): void
    {
        $service = $this->servico(['services_type_id' => null]);
        $service->forceFill(['is_custom' => true, 'custom_duration_minutes' => null])->save();

        $this->assertNull($service->fresh()->durationMinutes());
    }

    // --- quem a usa -------------------------------------------------------

    public function test_a_marcacao_bloqueia_o_tempo_todo_e_nao_so_uma_unidade(): void
    {
        $service = $this->servico(['quantity' => 3], tempoDoTipo: 60);
        $service->forceFill([
            'pending_schedule_data' => [
                'scheduled' => true,
                'schedule' => [
                    'scheduled_day' => Carbon::today('Europe/Lisbon')->addWeek()->toDateString(),
                    'scheduled_time_start' => '09:00',
                ],
            ],
        ])->save();

        app(MaterializePendingSchedule::class)->handle($service->refresh());

        $schedule = $service->vendor->schedules()->where('service_id', $service->id)->firstOrFail();

        $this->assertSame(
            '12:00:00',
            Carbon::parse($schedule->scheduled_time_end)->format('H:i:s'),
            'três unidades de uma hora ocupam das 9 ao meio-dia, não das 9 às 10',
        );
    }

    /**
     * O caminho síncrono — cartão que passa à primeira, saldo ou voucher.
     *
     * Exercitado por reflexão: o caminho público exige o gateway de pagamento,
     * que não existe neste ambiente. O que se prova aqui é o que este método
     * grava, que era onde a repetição se perdia e onde a duração era a errada.
     */
    public function test_o_caminho_sincrono_guarda_a_repeticao_e_a_duracao_certa(): void
    {
        $service = $this->servico(['quantity' => 2], tempoDoTipo: 60);

        $request = OpenServiceRequest::create('/', 'POST', [
            'scheduled' => true,
            'schedule' => [
                'scheduled_day' => Carbon::today('Europe/Lisbon')->addWeek()->toDateString(),
                'scheduled_time_start' => '14:00',
                'recurrence' => 'weekly',
            ],
        ]);

        $metodo = new ReflectionMethod(OpenServiceController::class, 'createScheduleIfRequested');
        $metodo->invoke(app(OpenServiceController::class), $request, $service->vendor, $service);

        $schedule = $service->vendor->schedules()->where('service_id', $service->id)->firstOrFail();

        $this->assertSame(
            'weekly',
            $schedule->recurrence?->value,
            'o cliente escolheu "repete todas as semanas" e pagou: a série tem de nascer',
        );
        $this->assertSame(
            '16:00:00',
            Carbon::parse($schedule->scheduled_time_end)->format('H:i:s'),
            'duas unidades de uma hora: das 14 às 16',
        );
    }
}
