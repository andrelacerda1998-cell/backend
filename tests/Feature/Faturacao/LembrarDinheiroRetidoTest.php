<?php

namespace Tests\Feature\Faturacao;

use App\Models\Vendor;
use App\Notifications\Vendor\DinheiroRetidoNotification;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\TecnicosParaFaturar;
use Tests\TestCase;

/**
 * Um lembrete por semana a quem tem dinheiro retido por uma coisa que só ele
 * pode dar (IBAN, morada fiscal, AT). Sem prazo nem perda: o dinheiro continua
 * a ser dele.
 */
class LembrarDinheiroRetidoTest extends TestCase
{
    use DatabaseTruncation, TecnicosParaFaturar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararFaturacao();
    }

    private function lembrar(): void
    {
        $this->artisan('vendors:lembrar-dinheiro-retido')->assertSuccessful();
    }

    private function assertLembrado(Vendor $vendor, string $razao, string $valor): void
    {
        Notification::assertSentTo(
            $vendor->user,
            DinheiroRetidoNotification::class,
            fn (DinheiroRetidoNotification $aviso) => $aviso->razao === $razao && $aviso->valor === $valor
        );
    }

    /** Uma linha em `notifications`, como a que a fila deixa quando o aviso sai. */
    private function lembradoHa(Vendor $vendor, int $dias): void
    {
        $vendor->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => DinheiroRetidoNotification::class,
            'data' => [],
            'created_at' => now()->subDays($dias),
            'updated_at' => now()->subDays($dias),
        ]);
    }

    public function test_lembra_quem_tem_o_dinheiro_retido_por_falta_de_iban(): void
    {
        $vendor = $this->tecnicoSemAt(['iban' => null]);
        $this->servicoFechado($vendor, 5000);

        $this->lembrar();

        $this->assertLembrado($vendor, 'iban_missing', '50,00 €');
    }

    /** Só a parte que espera pela fatura; o resto paga-se e não é razão para avisar. */
    public function test_lembra_a_parte_que_espera_pela_at(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->servicoFechado($vendor, 7500);
        $this->creditoPromocional($vendor, 2000);

        $this->lembrar();

        $this->assertLembrado($vendor, 'at_user_missing', '75,00 €');
    }

    /** A fatura por emitir com a AT dada é do lado da Piquet: não se incomoda o técnico. */
    public function test_nao_lembra_o_que_esta_do_lado_da_piquet(): void
    {
        $this->servicoFechado($this->tecnico(), 7500);

        $this->lembrar();

        Notification::assertNothingSent();
    }

    public function test_nao_lembra_quem_nao_tem_nada_retido(): void
    {
        $vendor = $this->tecnico();
        $this->comFatura($this->servicoFechado($vendor, 7500));

        $this->lembrar();

        Notification::assertNothingSent();
    }

    public function test_um_lembrete_por_semana_no_maximo(): void
    {
        $vendor = $this->tecnicoSemAt(['iban' => null]);
        $this->servicoFechado($vendor, 5000);

        $this->lembrar();
        $this->lembrar();

        Notification::assertSentToTimes($vendor->user, DinheiroRetidoNotification::class, 1);
    }

    /** A proteção que sobrevive a um deploy é a linha em `notifications`, não a cache. */
    public function test_lembrado_ha_tres_dias_nao_volta_a_ser(): void
    {
        $vendor = $this->tecnicoSemAt(['iban' => null]);
        $this->servicoFechado($vendor, 5000);
        $this->lembradoHa($vendor, 3);

        $this->lembrar();

        Notification::assertNothingSent();
    }

    public function test_lembrado_ha_oito_dias_volta_a_ser(): void
    {
        $vendor = $this->tecnicoSemAt(['iban' => null]);
        $this->servicoFechado($vendor, 5000);
        $this->lembradoHa($vendor, 8);

        $this->lembrar();

        $this->assertLembrado($vendor, 'iban_missing', '50,00 €');
    }

    public function test_dry_run_nao_envia(): void
    {
        $vendor = $this->tecnicoSemAt(['iban' => null]);
        $this->servicoFechado($vendor, 5000);

        $this->artisan('vendors:lembrar-dinheiro-retido', ['--dry-run' => true])->assertSuccessful();

        Notification::assertNothingSent();
    }

    /** As frases existem nas línguas da app: sem elas o push saía com a chave. */
    public function test_o_aviso_tem_texto_em_todas_as_linguas(): void
    {
        $aviso = new DinheiroRetidoNotification('75,00 €', 'at_user_missing');

        foreach (['pt-pt', 'en', 'es', 'fr', 'de'] as $lingua) {
            $vendor = $this->tecnicoSemAt();
            $vendor->user->language = $lingua;

            $dados = $aviso->toArray($vendor->user);

            $this->assertStringContainsString('75,00 €', $dados['title'], $lingua);
            $this->assertStringNotContainsString('notifications.', $dados['title'].$dados['body'], $lingua);
        }
    }
}
