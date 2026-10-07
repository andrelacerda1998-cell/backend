<?php

namespace Tests\Feature\Vendors;

use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\Location;
use App\Notifications\Vendor\OnlineExpirouNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O "Online" expira ao fim de 72 h sem localização.
 *
 * A 07/10 havia 50 técnicos Online e 46 não mandavam a localização há mais de
 * uma semana: recebiam os convites agendados e deixavam-nos expirar.
 */
class ExpirarOnlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Notification::fake();
        // 11h em Lisboa: dentro do horário.
        Carbon::setTestNow(Carbon::parse('2026-10-07 11:00', 'Europe/Lisbon')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sem_localizacao_ha_mais_de_72_horas_passa_a_offline_e_e_avisado(): void
    {
        $vendor = $this->tecnico(localizacaoHaHoras: 73);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        $this->assertSame(StatusVendor::OFFLINE, $vendor->fresh()->status);
        Notification::assertSentTo($vendor->user, OnlineExpirouNotification::class);
    }

    public function test_antes_das_72_horas_continua_online(): void
    {
        $vendor = $this->tecnico(localizacaoHaHoras: 71);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        $this->assertSame(StatusVendor::ONLINE, $vendor->fresh()->status);
        Notification::assertNotSentTo($vendor->user, OnlineExpirouNotification::class);
    }

    public function test_quem_manda_a_localizacao_nunca_expira(): void
    {
        $vendor = $this->tecnico(localizacaoHaHoras: 0);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        $this->assertSame(StatusVendor::ONLINE, $vendor->fresh()->status);
    }

    public function test_sem_localizacao_nenhuma_e_ficha_parada_ha_dias_passa_a_offline(): void
    {
        $vendor = $this->tecnico(localizacaoHaHoras: null, fichaMudouHaHoras: 100);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        $this->assertSame(StatusVendor::OFFLINE, $vendor->fresh()->status);
    }

    /** Pôr-se Online grava a ficha: quem acabou de o fazer não expira já. */
    public function test_sem_localizacao_nenhuma_mas_acabou_de_ficar_online_continua(): void
    {
        $vendor = $this->tecnico(localizacaoHaHoras: null, fichaMudouHaHoras: 1);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        $this->assertSame(StatusVendor::ONLINE, $vendor->fresh()->status);
    }

    public function test_com_um_servico_em_curso_nao_expira(): void
    {
        foreach ([ServiceStatus::ACCEPTED, ServiceStatus::ARRIVED, ServiceStatus::FINISHED] as $estado) {
            $vendor = $this->tecnico(localizacaoHaHoras: 100);
            Service::factory()->create([
                'customer_id' => User::factory()->create()->id,
                'vendor_id' => $vendor->id,
                'services_type_id' => ServicesType::factory()->create()->id,
                'status' => $estado,
            ]);

            $this->artisan('vendors:expirar-online')->assertSuccessful();

            $this->assertSame(StatusVendor::ONLINE, $vendor->fresh()->status, "expirou com um serviço {$estado->value}");
        }
    }

    /** Um serviço já fechado não conta: não é trabalho em mãos. */
    public function test_um_servico_fechado_nao_o_segura(): void
    {
        $vendor = $this->tecnico(localizacaoHaHoras: 100);
        Service::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => ServicesType::factory()->create()->id,
            'status' => ServiceStatus::CLOSED,
        ]);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        $this->assertSame(StatusVendor::OFFLINE, $vendor->fresh()->status);
    }

    public function test_contas_de_teste_ficam_de_fora(): void
    {
        $vendor = $this->tecnico(localizacaoHaHoras: 100, teste: true);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        $this->assertSame(StatusVendor::ONLINE, $vendor->fresh()->status);
    }

    public function test_quem_ja_esta_offline_nao_recebe_nada(): void
    {
        $vendor = $this->tecnico(localizacaoHaHoras: 100, status: StatusVendor::OFFLINE);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        Notification::assertNotSentTo($vendor->user, OnlineExpirouNotification::class);
    }

    public function test_de_noite_ninguem_expira(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 23:30', 'Europe/Lisbon')->utc());
        $vendor = $this->tecnico(localizacaoHaHoras: 100);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        $this->assertSame(StatusVendor::ONLINE, $vendor->fresh()->status);
        Notification::assertNotSentTo($vendor->user, OnlineExpirouNotification::class);
    }

    public function test_o_dry_run_nao_mexe_em_ninguem(): void
    {
        $vendor = $this->tecnico(localizacaoHaHoras: 100);

        $this->artisan('vendors:expirar-online', ['--dry-run' => true])
            ->expectsOutput("passaria a Offline o vendor {$vendor->id}")
            ->assertSuccessful();

        $this->assertSame(StatusVendor::ONLINE, $vendor->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_as_horas_vem_da_configuracao(): void
    {
        config(['services.request.online_expira_horas' => 24]);
        $vendor = $this->tecnico(localizacaoHaHoras: 30);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        $this->assertSame(StatusVendor::OFFLINE, $vendor->fresh()->status);
    }

    /** Depois de expirar, os convites agendados deixam de lhe chegar. */
    public function test_depois_de_expirar_ja_nao_entra_nos_convites(): void
    {
        $vendor = $this->tecnico(localizacaoHaHoras: 100);

        $this->artisan('vendors:expirar-online')->assertSuccessful();

        $this->assertFalse(
            Vendor::query()->where('status', StatusVendor::ONLINE)->whereKey($vendor->id)->exists(),
        );
    }

    public function test_a_notificacao_diz_quantos_dias_e_como_voltar(): void
    {
        $user = User::factory()->create(['language' => 'pt-pt']);

        $mensagem = (new OnlineExpirouNotification(72))->toArray($user);

        $this->assertSame('online_expirou', $mensagem['type']);
        $this->assertStringContainsString('3 dias', $mensagem['body']);
        $this->assertStringContainsString('Abre a app', $mensagem['body']);
    }

    private function tecnico(
        ?int $localizacaoHaHoras,
        StatusVendor $status = StatusVendor::ONLINE,
        bool $teste = false,
        int $fichaMudouHaHoras = 0,
    ): Vendor {
        $user = User::factory()->create(['is_test' => $teste]);
        $vendor = Vendor::factory()->create(['user_id' => $user->id, 'status' => $status]);

        if ($localizacaoHaHoras !== null) {
            $location = $vendor->currentLocation()->save(new Location(['latitude' => 38.71, 'longitude' => -9.14]));
            $quando = now()->subHours($localizacaoHaHoras);
            Location::whereKey($location->getKey())->update(['updated_at' => $quando, 'created_at' => $quando]);
        }

        Vendor::whereKey($vendor->id)->update(['updated_at' => now()->subHours($fichaMudouHaHoras)]);

        return $vendor->fresh();
    }
}
