<?php

namespace Tests\Feature\Carteira;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Jobs\Services\CreateInvoiceJob;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet\WalletCredit;
use App\Notifications\Customer\ConviteRecompensaNotification;
use App\Notifications\Customer\CreditoAExpirarNotification;
use App\Services\Carteira\CarteiraDoCliente;
use App\Services\Carteira\Convites;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Os avisos do programa de convites (14/10/2026):
 *  - o crédito a expirar, uma semana antes e uma vez por crédito;
 *  - o email a quem convida, além do push, quando ganha os 5 €.
 */
class AvisosDosConvitesTest extends TestCase
{
    use RefreshDatabase;

    private Convites $convites;

    private CarteiraDoCliente $carteira;

    private int $telefone = 913000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
        Bus::fake([CreateInvoiceJob::class]);
        config(['scout.driver' => 'null']);

        $this->convites = app(Convites::class);
        $this->carteira = app(CarteiraDoCliente::class);
    }

    private function cliente(string $nome = 'Ana', array $atributos = []): User
    {
        return User::factory()->create([
            'first_name' => $nome,
            'phone_number' => '+351'.($this->telefone++),
            ...$atributos,
        ]);
    }

    private function credito(User $cliente, int $dias, string $motivo = Convites::MOTIVO_QUEM_CONVIDA, int $valor = 500): WalletCredit
    {
        return $this->carteira->creditarConvites($cliente, $valor, now()->addDays($dias), $motivo);
    }

    private function jaPagou(User $cliente): void
    {
        Service::factory()->create(['customer_id' => $cliente->id, 'status' => ServiceStatus::CLOSED, 'payment_status' => PaymentStatus::PAID]);
    }

    private function avisar(array $opcoes = []): void
    {
        $this->artisan('carteira:avisar-expiracao', $opcoes)->assertSuccessful();
    }

    // ------------------------------------------------------ crédito a expirar

    public function test_avisa_uma_semana_antes_e_so_uma_vez(): void
    {
        $ana = $this->cliente();
        $credito = $this->credito($ana, 5);

        $this->avisar();
        $this->avisar();

        Notification::assertSentToTimes($ana, CreditoAExpirarNotification::class, 1);
        $this->assertNotNull($credito->fresh()->expiry_warned_at);
    }

    public function test_nao_avisa_o_que_so_expira_depois_da_semana(): void
    {
        $this->credito($this->cliente(), 10);

        $this->avisar();

        Notification::assertNothingSent();
    }

    public function test_um_aviso_por_cliente_com_a_soma_e_a_data_mais_proxima(): void
    {
        $ana = $this->cliente();
        $this->credito($ana, 6);
        $this->credito($ana, 3);

        $this->avisar();

        Notification::assertSentToTimes($ana, CreditoAExpirarNotification::class, 1);
        Notification::assertSentTo($ana, CreditoAExpirarNotification::class, function (CreditoAExpirarNotification $aviso) {
            return $aviso->valor === 1000
                && $aviso->expiraEm->isSameDay(now()->addDays(3))
                && $aviso->soNoPrimeiroServico === false;
        });
    }

    /** O crédito de quem foi convidado só serve no primeiro serviço: avisa com o mínimo. */
    public function test_o_convidado_que_ainda_nao_pagou_e_avisado_com_o_minimo(): void
    {
        $bruno = $this->cliente('Bruno', ['language' => 'pt-pt']);
        $this->credito($bruno, 5, Convites::MOTIVO_AMIGO);

        $this->avisar();

        Notification::assertSentTo($bruno, CreditoAExpirarNotification::class, function (CreditoAExpirarNotification $aviso) use ($bruno) {
            $texto = $aviso->toArray($bruno);

            return $aviso->soNoPrimeiroServico
                && str_contains($texto['title'], '5 €')
                && str_contains($texto['body'], '30 €');
        });
    }

    /** Já pagou um serviço: o crédito de convidado já não serve, e avisar seria prometer o que não há. */
    public function test_nao_avisa_o_convidado_que_ja_pagou_um_servico(): void
    {
        $bruno = $this->cliente('Bruno');
        $credito = $this->credito($bruno, 5, Convites::MOTIVO_AMIGO);
        $this->jaPagou($bruno);

        $this->avisar();

        Notification::assertNothingSent();
        $this->assertNotNull($credito->fresh()->expiry_warned_at, 'decidido: não se volta a ele amanhã');
    }

    public function test_credito_gasto_ou_ja_expirado_nao_e_avisado(): void
    {
        $gasto = $this->credito($this->cliente('Rui'), 5);
        $gasto->update(['remaining' => 0]);
        $expirado = $this->credito($this->cliente('Zé'), 5);
        $expirado->update(['expired_at' => now()]);

        $this->avisar();

        Notification::assertNothingSent();
    }

    public function test_dry_run_nao_envia_nem_marca(): void
    {
        $credito = $this->credito($this->cliente(), 5);

        $this->avisar(['--dry-run' => true]);

        Notification::assertNothingSent();
        $this->assertNull($credito->fresh()->expiry_warned_at);
    }

    public function test_o_aviso_tem_texto_nas_cinco_linguas(): void
    {
        $aviso = new CreditoAExpirarNotification(500, now()->addDays(5), true);

        foreach (['pt-pt', 'en', 'es', 'fr', 'de'] as $lingua) {
            $cliente = $this->cliente('L'.$lingua, ['language' => $lingua]);
            $texto = $aviso->toArray($cliente);

            $this->assertStringContainsString('5 €', $texto['title'], $lingua);
            $this->assertStringNotContainsString('notifications.', $texto['title'].$texto['body'], $lingua);
        }
    }

    // ------------------------------------------------- email a quem convida

    private function convidarEFechar(User $quemConvida, User $amigo): void
    {
        $this->convites->aplicar($amigo, $this->convites->codigoDe($quemConvida)->code);

        $servico = Service::factory()->create([
            'customer_id' => $amigo->id,
            'status' => ServiceStatus::FINISHED,
            'payment_status' => PaymentStatus::PAID,
            'payment_order_id' => null,
            'original_amount' => 4500,
            'amount' => 4500,
            'amount_for_vendor' => 3375,
        ]);
        $servico->status = ServiceStatus::CLOSED; // ServiceObserver::updated
        $servico->save();
    }

    public function test_quem_convida_recebe_tambem_um_email_com_o_nome_do_amigo(): void
    {
        $ana = $this->cliente('Ana', ['language' => 'pt-pt']);
        $this->jaPagou($ana);
        $bruno = $this->cliente('Bruno');

        $this->convidarEFechar($ana, $bruno);

        Notification::assertSentTo($ana, ConviteRecompensaNotification::class, function (ConviteRecompensaNotification $n, array $canais) use ($ana) {
            if (! in_array('mail', $canais, true)) {
                return false;
            }
            $email = $n->toMail($ana);
            $corpo = implode(' ', $email->introLines);

            return str_contains($email->subject, '5 €')
                && $email->greeting === 'Olá Ana,'
                && str_contains($corpo, 'Bruno')
                && str_contains($corpo, '6 meses');
        });
    }

    /** Conta apagada e anonimizada: não há a quem escrever. */
    public function test_nao_escreve_para_um_email_anonimizado(): void
    {
        $quem = $this->cliente('Ana');
        $notificacao = new ConviteRecompensaNotification(new \App\Models\Referral\Referral);

        $this->assertContains('mail', $notificacao->via($quem));

        $quem->email = 'anon-123@anonymized.invalid';
        $this->assertNotContains('mail', $notificacao->via($quem));
    }

    public function test_o_email_tem_texto_nas_cinco_linguas(): void
    {
        $notificacao = new ConviteRecompensaNotification(new \App\Models\Referral\Referral);

        foreach (['pt-pt', 'en', 'es', 'fr', 'de'] as $lingua) {
            $cliente = $this->cliente('M'.$lingua, ['language' => $lingua]);
            $email = $notificacao->toMail($cliente);

            $this->assertStringNotContainsString('notifications.', $email->subject.implode(' ', $email->introLines).$email->salutation, $lingua);
        }
    }
}
