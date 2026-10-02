<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O QUE ACONTECE AO DINHEIRO DE UM EXTRA QUANDO O SERVIÇO É CANCELADO.
 *
 * Os extras nascem e são aprovados com o serviço em ARRIVED -- e ARRIVED é um
 * dos estados de onde ainda se pode cancelar (`CancelService`, linha 246).
 * O `CancelService` não menciona extras em lado nenhum (grep: zero).
 *
 * Isto não é uma acusação: é a pergunta que falta responder. Uma peça aprovada
 * e cobrada num serviço que depois morre fica onde? O cliente pagou, o técnico
 * pode já a ter instalado, e o `CloseService` -- que é quem credita extras --
 * nunca corre num serviço cancelado.
 */
class ExtrasNumServicoCanceladoTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types',
        'operation_areas', 'service_extras', 'transactions', 'transfers', 'schedule',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
    }

    private function servicoEmExecucaoComExtraAprovado(): array
    {
        $user = User::factory()->create();
        $v = Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id, 'price_rate' => 30.0]);
        $cliente = User::factory()->create();

        $s = new Service();
        $s->forceFill([
            'customer_id' => $cliente->id,
            'vendor_id' => $v->id,
            'quantity' => 1,
            'status' => ServiceStatus::ARRIVED,
            'payment_status' => PaymentStatus::PAID,
            'distance' => 2,
            'amount' => 4500,
            'amount_for_vendor' => 3375,
            'credit_used' => 0,
            'price_rate' => 0,
            'is_custom' => 0,
            'is_test' => 0,
            'arrived_at' => now(),
        ])->save();

        // Peça de 50,00 €, aprovada e COBRADA ao cliente.
        $extra = $s->extras()->create([
            'type' => 'part',
            'description' => 'Torneira nova',
            'amount' => 5000,
            'status' => 'approved',
            'payment_status' => 'paid',
            'charged_at' => now(),
        ]);

        return [$v, $s->fresh(), $extra];
    }

    /**
     * O extra cobrado continua `paid` e `approved` depois do cancelamento,
     * sem crédito ao técnico e sem reembolso ao cliente.
     *
     * Este teste DOCUMENTA o comportamento de hoje. Se amanhã se decidir
     * reembolsar ou creditar, ele falha e obriga a atualizar a regra de
     * propósito, em vez de a mudar por acidente.
     */
    public function test_o_extra_cobrado_fica_por_resolver_quando_o_servico_e_cancelado(): void
    {
        [$v, $s, $extra] = $this->servicoEmExecucaoComExtraAprovado();

        $saldoTecnicoAntes = (int) $v->user->balance;
        $saldoClienteAntes = (int) $s->customer->balance;

        $s->forceFill(['status' => ServiceStatus::CANCELED])->save();

        $extra->refresh();

        $this->assertSame('approved', $extra->status, 'o extra continua aprovado');
        $this->assertSame('paid', $extra->payment_status, 'e continua marcado como cobrado');
        $this->assertNull($extra->vendor_credited_at, 'o técnico nunca foi creditado');

        // Nem um nem outro viram o dinheiro.
        $this->assertSame($saldoTecnicoAntes, (int) $v->user->fresh()->balance);
        $this->assertSame($saldoClienteAntes, (int) $s->customer->fresh()->balance);
    }

    /**
     * E o `CloseService` -- quem credita extras -- nunca corre num cancelado:
     * o extra fica fora de qualquer acerto, para sempre.
     */
    public function test_nada_credita_o_extra_depois_do_cancelamento(): void
    {
        [$v, $s, $extra] = $this->servicoEmExecucaoComExtraAprovado();
        $s->forceFill(['status' => ServiceStatus::CANCELED])->save();

        // Não há comando nem evento que apanhe extras de serviços cancelados.
        $porResolver = \App\Models\ServiceExtra::query()
            ->where('status', 'approved')
            ->whereNull('vendor_credited_at')
            ->whereHas('service', fn ($q) => $q->where('status', ServiceStatus::CANCELED))
            ->count();

        $this->assertSame(1, $porResolver, 'há 1 extra pago e pendurado num serviço cancelado');
    }
}
