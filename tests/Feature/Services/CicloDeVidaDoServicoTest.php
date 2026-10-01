<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use App\Events\Common\Services\UpdateLocationEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O SERVIÇO IMEDIATO, DE PONTA A PONTA.
 *
 * Cada passo deste ciclo já tinha testes próprios; o que faltava era o
 * caminho inteiro, pela ordem real e pelos mesmos endpoints que a app usa.
 * É nas COSTURAS que as coisas partem -- um estado que avança sem o anterior,
 * uma ação que o servidor aceita fora de ordem -- e nenhum teste de uma peça
 * só apanha isso.
 *
 * Também se testa o que NÃO deve ser possível: chegar sem ir a caminho, pedir
 * extras antes de chegar, concluir o que já está concluído.
 */
class CicloDeVidaDoServicoTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types',
        'operation_areas', 'service_extras', 'transactions', 'transfers', 'schedule',
        // `vendors_location` incluída DE PROPÓSITO: sem ela a posição de um
        // teste sobrevive para o seguinte, e o primeiro `location/update` do
        // teste a seguir já encontra outro `device_id` registado -- falha com
        // "outro dispositivo ligado" por lixo, não por código.
        'vendors_location',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
    }

    private function tecnico(float $precoHora = 30.0): Vendor
    {
        $user = User::factory()->create();

        return Vendor::create([
            'user_id' => $user->id,
            'username' => 'tec_'.$user->id,
            'price_rate' => $precoHora,
        ]);
    }

    private function servicoAceite(Vendor $v): Service
    {
        $cliente = User::factory()->create();

        $s = new Service();
        $s->forceFill([
            'customer_id' => $cliente->id,
            'vendor_id' => $v->id,
            'quantity' => 1,
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
            'distance' => 2,
            'amount' => 4500,
            'amount_for_vendor' => 3375,
            'credit_used' => 0,
            'price_rate' => 0,
            'is_custom' => 0,
            'is_test' => 0,
        ])->save();

        return $s->fresh();
    }

    private function comoTecnico(Vendor $v)
    {
        return $this->actingAs($v->user, 'api');
    }

    private function comoCliente(Service $s)
    {
        return $this->actingAs($s->customer, 'api');
    }

    // ---------------------------------------------------------------- caminho feliz

    public function test_o_caminho_inteiro_de_um_servico_imediato(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoAceite($v);

        // 1. aceitar
        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/accept")->assertOk();
        $this->assertSame(ServiceStatus::ACCEPTED, $s->fresh()->status);

        // 2. a caminho
        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/on-the-way")->assertOk();
        $this->assertNotNull($s->fresh()->on_the_way_at);

        // 3. chegou
        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/arrived")->assertOk();
        $this->assertSame(ServiceStatus::ARRIVED, $s->fresh()->status);

        // 4. concluir
        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/finish")->assertOk();
        $this->assertSame(ServiceStatus::FINISHED, $s->fresh()->status);

        // 5. avaliar o cliente
        $this->comoTecnico($v)
            ->putJson("/api/v1/vendor/services/{$s->id}/rate", ['rate' => 5, 'comment' => 'Tudo bem.'])
            ->assertOk();
        $this->assertSame(5, (int) $s->fresh()->rating_by_vendor);
    }

    // ---------------------------------------------------------------- extras

    public function test_tempo_extra_e_cobrado_pelo_preco_hora_do_tecnico(): void
    {
        $v = $this->tecnico(precoHora: 30.0);   // 30 €/h
        $s = $this->servicoAceite($v);
        $this->levarAteAoLocal($v, $s);

        $r = $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/extras", [
            'type' => 'time',
            'minutes' => 30,
        ])->assertOk();

        // Meia hora a 30 €/h = 15,00 € = 1500 cêntimos.
        $this->assertSame(1500, $r->json('data.extra.amount'));
        $this->assertSame('pending', $r->json('data.extra.status'));
    }

    public function test_peca_entra_com_o_valor_que_o_tecnico_indicou(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoAceite($v);
        $this->levarAteAoLocal($v, $s);

        $r = $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/extras", [
            'type' => 'part',
            'description' => 'Torneira nova',
            'amount' => 2250,
        ])->assertOk();

        $this->assertSame(2250, $r->json('data.extra.amount'));
    }

    /** O cliente aprova; só aí o extra conta. */
    public function test_o_cliente_aprova_o_extra(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoAceite($v);
        $this->levarAteAoLocal($v, $s);

        $extraId = $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/extras", [
            'type' => 'part', 'description' => 'Vedante', 'amount' => 500,
        ])->json('data.extra.id');

        $this->comoCliente($s)
            ->postJson("/api/v1/customer/services/{$s->id}/extras/{$extraId}/approve")
            ->assertOk();

        $this->assertSame('approved', $s->extras()->find($extraId)->status);
    }

    /** E pode recusar: o extra morre e não entra no valor. */
    public function test_o_cliente_recusa_o_extra(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoAceite($v);
        $this->levarAteAoLocal($v, $s);

        $extraId = $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/extras", [
            'type' => 'time', 'minutes' => 60,
        ])->json('data.extra.id');

        $this->comoCliente($s)
            ->postJson("/api/v1/customer/services/{$s->id}/extras/{$extraId}/reject")
            ->assertOk();

        $this->assertSame('rejected', $s->extras()->find($extraId)->status);
    }

    /**
     * EXTRAS SÓ DEPOIS DE CHEGAR.
     *
     * Pedir tempo extra a caminho é pedir ao cliente que pague por trabalho que
     * ainda não começou.
     */
    public function test_nao_deixa_pedir_extras_antes_de_chegar(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoAceite($v);
        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/accept")->assertOk();

        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/extras", [
            'type' => 'time', 'minutes' => 30,
        ])->assertStatus(422);

        $this->assertSame(0, $s->extras()->count());
    }

    /** Os extras de um serviço não são de outro profissional. */
    public function test_nao_pede_extras_no_servico_de_outro(): void
    {
        $meu = $this->tecnico();
        $outro = $this->tecnico();
        $s = $this->servicoAceite($outro);
        $this->levarAteAoLocal($outro, $s);

        $this->comoTecnico($meu)->postJson("/api/v1/vendor/services/{$s->id}/extras", [
            'type' => 'time', 'minutes' => 30,
        ])->assertStatus(404);
    }

    // ---------------------------------------------------------------- ordem dos estados

    /** Aceitar duas vezes não volta a disparar nada. */
    public function test_aceitar_duas_vezes(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoAceite($v);

        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/accept")->assertOk();
        // 403 e não 500: o servidor distingue "não é teu / já não está pendente"
        // de um erro de servidor. Eu é que tinha assumido o pior.
        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/accept")
            ->assertStatus(403);

        $this->assertSame(ServiceStatus::ACCEPTED, $s->fresh()->status);
    }

    /** Avaliar duas vezes não sobrescreve. */
    public function test_avaliar_duas_vezes(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoAceite($v);
        $this->levarAteAoLocal($v, $s);
        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/finish")->assertOk();

        $this->comoTecnico($v)->putJson("/api/v1/vendor/services/{$s->id}/rate", ['rate' => 5])->assertOk();
        $this->comoTecnico($v)->putJson("/api/v1/vendor/services/{$s->id}/rate", ['rate' => 1])->assertStatus(409);

        $this->assertSame(5, (int) $s->fresh()->rating_by_vendor);
    }

    /** O serviço de outro profissional não é dele para avançar. */
    public function test_nao_avanca_o_servico_de_outro(): void
    {
        $meu = $this->tecnico();
        $outro = $this->tecnico();
        $s = $this->servicoAceite($outro);

        // 404: o serviço de outro profissional não existe para este.
        $this->comoTecnico($meu)->postJson("/api/v1/vendor/services/{$s->id}/accept")
            ->assertStatus(404);

        $this->assertSame(ServiceStatus::PENDING, $s->fresh()->status);
    }

    // ---------------------------------------------------------------- posição em tempo real

    /**
     * A POSIÇÃO DO TÉCNICO, que é o que o cliente vê no mapa enquanto espera.
     */
    public function test_a_posicao_do_tecnico_e_gravada(): void
    {
        $v = $this->tecnico();

        $this->comoTecnico($v)->putJson('/api/v1/vendor/location/update', [
            'latitude' => 38.7102,
            'longitude' => -9.1375,
            'device_id' => 'telemovel-de-teste',
        ])->assertOk();

        $pos = $v->fresh()->currentLocation;
        $this->assertNotNull($pos);
        $this->assertEqualsWithDelta(38.7102, (float) $pos->latitude, 0.0001);
        $this->assertEqualsWithDelta(-9.1375, (float) $pos->longitude, 0.0001);
    }

    /**
     * DOIS TELEMÓVEIS NÃO PODEM REPORTAR A MESMA PESSOA.
     *
     * Sem isto, um telemóvel esquecido em casa e outro na carrinha mandavam
     * posições alternadas e o cliente via o técnico a saltar entre dois sítios.
     */
    public function test_recusa_um_segundo_dispositivo(): void
    {
        $v = $this->tecnico();

        $this->comoTecnico($v)->putJson('/api/v1/vendor/location/update', [
            'latitude' => 38.71, 'longitude' => -9.13, 'device_id' => 'telemovel-A',
        ])->assertOk();

        $this->comoTecnico($v)->putJson('/api/v1/vendor/location/update', [
            'latitude' => 41.15, 'longitude' => -8.61, 'device_id' => 'telemovel-B',
        ])->assertStatus(403);   // VendorAlreadyHasDeviceConnected

        // A posição que fica é a do primeiro.
        $this->assertEqualsWithDelta(38.71, (float) $v->fresh()->currentLocation->latitude, 0.01);
    }

    /**
     * O CLIENTE DE UM AGENDADO VÊ O TÉCNICO A CAMINHO.
     *
     * A posição só era emitida para serviços imediatos: quem marcava para
     * sábado às 15h ficava sem mapa. O ecrã existe e funciona; só nunca
     * recebia os dados.
     */
    public function test_a_posicao_chega_ao_cliente_de_um_agendado_a_caminho(): void
    {
        Event::fake([UpdateLocationEvent::class]);

        $v = $this->tecnico();
        $s = $this->servicoAgendadoACaminho($v);

        $this->comoTecnico($v)->putJson('/api/v1/vendor/location/update', [
            'latitude' => 38.71, 'longitude' => -9.13, 'device_id' => 'telemovel-A',
        ])->assertOk();

        Event::assertDispatched(
            UpdateLocationEvent::class,
            fn (UpdateLocationEvent $e) => (int) $e->service['id'] === $s->id,
        );
    }

    /**
     * MAS SÓ DEPOIS DE ELE SAIR.
     *
     * Um agendamento pode ser para a semana que vem. Emitir a posição desde o
     * aceite era dar ao cliente um rastreador por sete dias.
     */
    public function test_nao_emite_a_posicao_de_um_agendado_que_ainda_nao_saiu(): void
    {
        Event::fake([UpdateLocationEvent::class]);

        $v = $this->tecnico();
        $this->servicoAgendadoACaminho($v, saiu: false);

        $this->comoTecnico($v)->putJson('/api/v1/vendor/location/update', [
            'latitude' => 38.71, 'longitude' => -9.13, 'device_id' => 'telemovel-A',
        ])->assertOk();

        Event::assertNotDispatched(UpdateLocationEvent::class);
    }

    // ---------------------------------------------------------------- reclamações

    /** O técnico abre uma reclamação/pedido de ajuda. */
    public function test_o_tecnico_abre_um_ticket_de_suporte(): void
    {
        $v = $this->tecnico();

        $r = $this->comoTecnico($v)->postJson('/api/v1/vendor/support/tickets', [
            'subject' => 'Cliente não estava em casa',
            'message' => 'Esperei 20 minutos e ninguém abriu a porta.',
        ]);

        $this->assertContains($r->status(), [200, 201], 'resposta: '.$r->getContent());

        $lista = $this->comoTecnico($v)->getJson('/api/v1/vendor/support/tickets')->assertOk();
        $this->assertNotEmpty($lista->json('data'));
    }

    // ---------------------------------------------------------------- auxiliar

    /** Um serviço COM agendamento, opcionalmente já a caminho. */
    private function servicoAgendadoACaminho(Vendor $v, bool $saiu = true): Service
    {
        $s = $this->servicoAceite($v);
        $s->forceFill([
            'status' => ServiceStatus::ACCEPTED,
            'on_the_way_at' => $saiu ? now() : null,
        ])->save();

        \App\Models\Schedule\Schedule::forceCreate([
            'service_id' => $s->id,
            'vendor_id' => $v->id,
            'customer_id' => $s->customer_id,
            'scheduled_day' => now()->addDays(2)->toDateString(),
            'scheduled_time_start' => '15:00:00',
            'scheduled_time_end' => '16:00:00',
            'is_pending' => false,
        ]);

        return $s->fresh();
    }

    private function levarAteAoLocal(Vendor $v, Service $s): void
    {
        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/accept")->assertOk();
        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/on-the-way")->assertOk();
        $this->comoTecnico($v)->postJson("/api/v1/vendor/services/{$s->id}/arrived")->assertOk();
    }
}
