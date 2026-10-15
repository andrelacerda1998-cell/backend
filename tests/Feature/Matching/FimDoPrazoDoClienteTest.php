<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\AddressType;
use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Address;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Customer\MatchingFailedNotification;
use App\Notifications\Vendor\MatchingOutcomeNotification;
use App\Services\Matching\MatchingService;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * Quando o tempo do cliente acaba, o pedido acaba — e toda a gente sabe.
 *
 * Reproduzido a 15/10/2026 no simulador, com o código do main:
 *  · voltar a pedir o mesmo serviço logo a seguir ao prazo devolvia o pedido
 *    morto (o `start()` devolve o que está aberto, e o `matching:advance` só
 *    o fecha até um minuto depois) — o cliente ficava preso;
 *  · o checkout ficava aberto a zero, e o cliente não recebia aviso nenhum;
 *  · o ecrã do fim dizia "nenhum técnico aceitou" a quem tinha dois à espera;
 *  · o técnico escolhido, sem pagamento, só sabia ao abrir a app;
 *  · "Pedir agora" recusado mostrava "O cliente não pode solicitar um serviço".
 */
class FimDoPrazoDoClienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    private function cliente(): User
    {
        $user = User::factory()->create(['phone_number_verified_at' => now()]);

        Address::create([
            'user_id' => $user->id,
            'name' => 'Casa',
            'street_name' => 'Rua Augusta',
            'street_number' => '100',
            'postal_code' => '1100-053',
            'city' => 'Lisboa',
            'municipality' => 'Lisboa',
            'state' => 'Lisboa',
            'country' => 'Portugal',
            'latitude' => 38.7104,
            'longitude' => -9.1366,
            'main_address' => true,
            'address_type' => AddressType::HOUSE_ADDRESS,
        ]);

        return $user->fresh();
    }

    /** Pedido imediato com dois sim há `$haSegundos` segundos. */
    private function pedidoComDoisSim(User $cliente, ServicesType $tipo, int $haSegundos): array
    {
        $service = Service::factory()->create([
            'customer_id' => $cliente->id,
            'services_type_id' => $tipo->id,
            'status' => ServiceStatus::MATCHING,
        ]);
        $service->forceFill(['candidates_ready_at' => now()->subSeconds($haSegundos)])->saveQuietly();

        $candidatos = collect([1, 2])->map(fn (int $rank) => ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => $rank,
            'wave' => 1,
            'status' => CandidateStatus::ACCEPTED,
            'quoted_amount' => 4500,
            'quoted_amount_for_vendor' => 3375,
            'quoted_distance' => 2.4,
            'notified_at' => now()->subSeconds($haSegundos + 30),
            'responded_at' => now()->subSeconds($haSegundos),
        ]));

        return [$service->refresh(), $candidatos];
    }

    /** Escolhido há `$haSegundos` segundos e por pagar. */
    private function porPagar(User $cliente, ServicesType $tipo, int $haSegundos): array
    {
        [$service, $candidatos] = $this->pedidoComDoisSim($cliente, $tipo, 30);
        $escolhido = $candidatos->first();

        $this->assertTrue(app(MatchingService::class)->select($escolhido));
        ServiceCandidate::whereKey($escolhido->id)->update(['updated_at' => now()->subSeconds($haSegundos)]);

        return [$service->refresh(), $escolhido->refresh()];
    }

    private function pedir(User $cliente, ServicesType $tipo)
    {
        return $this->actingAs($cliente, 'api')
            ->postJson('/api/v1/customer/services/matching', ['service_type' => $tipo->id]);
    }

    public function test_pedir_outra_vez_depois_do_prazo_de_escolher_abre_um_pedido_novo(): void
    {
        $cliente = $this->cliente();
        $tipo = ServicesType::factory()->create();
        [$morto] = $this->pedidoComDoisSim($cliente, $tipo, 600);

        $novo = $this->pedir($cliente, $tipo)->assertSuccessful()->json('data.service.id');

        $this->assertNotSame($morto->id, $novo, 'devolvia o pedido expirado e o cliente ficava preso nele');
        $this->assertSame(ServiceStatus::MATCHING_FAILED, $morto->refresh()->status);
    }

    public function test_pedir_outra_vez_depois_do_prazo_de_pagar_abre_um_pedido_novo(): void
    {
        $cliente = $this->cliente();
        $tipo = ServicesType::factory()->create();
        [$morto] = $this->porPagar($cliente, $tipo, 400);

        $novo = $this->pedir($cliente, $tipo)->assertSuccessful()->json('data.service.id');

        $this->assertNotSame($morto->id, $novo);
        $this->assertSame(ServiceStatus::MATCHING_FAILED, $morto->refresh()->status);
    }

    /** O que já estava certo continua: com o relógio a correr, é o mesmo pedido. */
    public function test_com_o_prazo_a_correr_continua_a_devolver_o_mesmo_pedido(): void
    {
        $cliente = $this->cliente();
        $tipo = ServicesType::factory()->create();
        [$aberto] = $this->pedidoComDoisSim($cliente, $tipo, 30);

        $this->pedir($cliente, $tipo)->assertSuccessful()->assertJsonPath('data.service.id', $aberto->id);
        $this->assertSame(ServiceStatus::MATCHING, $aberto->refresh()->status);
    }

    /** Um MB Way por confirmar não é cortado: quem decide é a janela do pagamento. */
    public function test_um_pagamento_em_curso_nao_e_cortado(): void
    {
        $cliente = $this->cliente();
        $tipo = ServicesType::factory()->create();
        [$aPagar] = $this->porPagar($cliente, $tipo, 400);
        $ordem = PaymentOrder::create([
            'user_id' => $cliente->id,
            'uuid' => (string) Str::uuid(),
            'amount' => 4500,
            'paid' => false,
            'status' => Status::PENDING_CONFIRMATION,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'fake',
            'service_uuid' => (string) Str::uuid(),
            'token' => 'fake-token',
        ]);
        $aPagar->forceFill(['payment_order_id' => $ordem->id])->saveQuietly();

        $this->assertFalse(app(MatchingService::class)->expirarSeJaPassou($aPagar->refresh()));
        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $aPagar->refresh()->status);
    }

    public function test_os_pedidos_abertos_nao_mostram_um_pedido_expirado(): void
    {
        $cliente = $this->cliente();
        $tipo = ServicesType::factory()->create();
        [$morto] = $this->porPagar($cliente, $tipo, 400);

        $dados = $this->actingAs($cliente, 'api')
            ->getJson('/api/v1/customer/services/matching/current')
            ->assertSuccessful()
            ->json('data');

        $this->assertNull($dados['request']);
        $this->assertSame([], $dados['requests']);
        $this->assertSame(ServiceStatus::MATCHING_FAILED, $morto->refresh()->status);
    }

    /** O pedido do técnico: o escolhido que fica sem pagamento recebe push. */
    public function test_o_escolhido_sem_pagamento_recebe_push(): void
    {
        $cliente = $this->cliente();
        [$service, $escolhido] = $this->porPagar($cliente, ServicesType::factory()->create(), 400);

        $this->assertTrue(app(MatchingService::class)->expireCheckout($service));

        $user = $escolhido->vendor->user;

        Notification::assertSentTo(
            $user,
            MatchingOutcomeNotification::class,
            fn (MatchingOutcomeNotification $n) => $n->via($user) === ['expo', 'database']
                && $n->toArray($user)['outcome'] === 'closed'
                && ! str_starts_with($n->toArray($user)['title'], 'notifications.'),
        );
    }

    /** Quem perde para outro continua sem push: essa regra não muda. */
    public function test_quem_perde_para_outro_continua_sem_push(): void
    {
        $cliente = $this->cliente();
        [, $candidatos] = $this->pedidoComDoisSim($cliente, ServicesType::factory()->create(), 30);
        $perdedor = $candidatos->last();

        app(MatchingService::class)->select($candidatos->first());

        $user = $perdedor->vendor->user;

        Notification::assertSentTo(
            $user,
            MatchingOutcomeNotification::class,
            fn (MatchingOutcomeNotification $n) => $n->via($user) === ['database'],
        );
    }

    public function test_o_cliente_sabe_que_o_tempo_para_pagar_acabou(): void
    {
        $cliente = $this->cliente();
        [$service] = $this->porPagar($cliente, ServicesType::factory()->create(), 400);

        app(MatchingService::class)->expireCheckout($service);

        Notification::assertSentTo(
            $cliente,
            MatchingFailedNotification::class,
            fn (MatchingFailedNotification $n) => $n->toArray($cliente)['title']
                === __('notifications.matchingExpired.pagamento_expirou.title', [], $cliente->language ?? 'pt-pt'),
        );
    }

    public function test_quem_tinha_profissionais_a_espera_nao_ouve_ninguem_disponivel(): void
    {
        $cliente = $this->cliente();
        [$service] = $this->pedidoComDoisSim($cliente, ServicesType::factory()->create(), 600);

        app(MatchingService::class)->fail($service);

        $lingua = $cliente->language ?? 'pt-pt';

        Notification::assertSentTo(
            $cliente,
            MatchingFailedNotification::class,
            fn (MatchingFailedNotification $n) => $n->toArray($cliente)['title']
                === __('notifications.matchingExpired.escolha_expirou.title', [], $lingua),
        );

        $this->actingAs($cliente, 'api')
            ->getJson("/api/v1/customer/services/matching/{$service->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.service.end_reason', 'expired');
    }

    public function test_sem_ninguem_a_aceitar_o_texto_e_o_de_sempre(): void
    {
        $cliente = $this->cliente();
        $service = Service::factory()->create([
            'customer_id' => $cliente->id,
            'services_type_id' => ServicesType::factory(),
            'status' => ServiceStatus::MATCHING,
        ]);

        app(MatchingService::class)->fail($service);

        Notification::assertSentTo(
            $cliente,
            MatchingFailedNotification::class,
            fn (MatchingFailedNotification $n) => $n->toArray($cliente)['title']
                === __('notifications.matchingFailed.title', [], $cliente->language ?? 'pt-pt'),
        );

        $this->actingAs($cliente, 'api')
            ->getJson("/api/v1/customer/services/matching/{$service->id}")
            ->assertJsonPath('data.service.end_reason', 'no_vendors');
    }

    /** O comando e o "pedir outra vez" no mesmo segundo não avisam duas vezes. */
    public function test_um_pedido_que_ja_acabou_nao_acaba_duas_vezes(): void
    {
        $cliente = $this->cliente();
        [$service] = $this->pedidoComDoisSim($cliente, ServicesType::factory()->create(), 600);

        app(MatchingService::class)->fail($service);
        app(MatchingService::class)->fail($service->refresh());

        Notification::assertSentToTimes($cliente, MatchingFailedNotification::class, 1);
    }

    public function test_a_recusa_diz_ao_cliente_porque(): void
    {
        $cliente = $this->cliente();
        $tipo = ServicesType::factory()->create();
        Service::factory()->create([
            'customer_id' => $cliente->id,
            'services_type_id' => $tipo->id,
            'status' => ServiceStatus::ACCEPTED,
            'payment_status' => 'Paid',
        ]);

        $mensagem = $this->pedir($cliente, $tipo)->assertStatus(403)->json('message');

        $this->assertNotSame(__('exceptions.services.customer_cannot_request_service'), $mensagem);
        $this->assertStringStartsWith(
            explode('(', __('exceptions.services.cannot_request.open_service', ['services' => '']))[0],
            $mensagem,
        );
    }

    /** Tocar em pagar depois do fim: a resposta diz o que aconteceu, em português. */
    public function test_pagar_depois_do_fim_diz_que_o_tempo_acabou(): void
    {
        $cliente = $this->cliente();
        [$service] = $this->porPagar($cliente, ServicesType::factory()->create(), 400);
        app(MatchingService::class)->expireCheckout($service);

        $this->actingAs($cliente, 'api')
            ->postJson("/api/v1/customer/services/matching/{$service->id}/checkout", ['method' => 'credit_card'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('exceptions.services.checkout_expired'));
    }

    public function test_os_textos_novos_existem_nas_cinco_linguas(): void
    {
        foreach (['pt-pt', 'en', 'es', 'fr', 'de'] as $lingua) {
            foreach (['escolha_expirou', 'pagamento_expirou'] as $motivo) {
                foreach (['title', 'description'] as $campo) {
                    $chave = "notifications.matchingExpired.{$motivo}.{$campo}";
                    $this->assertNotSame($chave, __($chave, [], $lingua), "{$lingua}: {$chave}");
                }
            }

            $this->assertNotSame(
                'exceptions.services.checkout_expired',
                __('exceptions.services.checkout_expired', [], $lingua),
                "{$lingua}: checkout_expired",
            );
        }
    }
}
