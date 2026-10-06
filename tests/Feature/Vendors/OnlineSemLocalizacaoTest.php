<?php

namespace Tests\Feature\Vendors;

use App\Enums\Services\AddressType;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\Address;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\Location;
use App\Notifications\Vendor\OnlineSemLocalizacaoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Técnicos Online que deixaram de mandar a localização são avisados — e só
 * avisados: continuam Online, porque é isso que lhes traz os agendados.
 */
class OnlineSemLocalizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        // 11h em Lisboa: dentro do horário dos avisos.
        Carbon::setTestNow(Carbon::parse('2026-10-06 11:00', 'Europe/Lisbon')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_online_sem_localizacao_ha_mais_de_uma_hora_e_avisado_e_continua_online(): void
    {
        $vendor = $this->tecnico(localizacaoHaMinutos: 120);

        $this->artisan('vendors:avisar-online-sem-localizacao')->assertSuccessful();

        Notification::assertSentTo($vendor->user, OnlineSemLocalizacaoNotification::class);
        $this->assertSame(StatusVendor::ONLINE, $vendor->fresh()->status);
    }

    public function test_online_sem_localizacao_nenhuma_tambem_e_avisado(): void
    {
        $vendor = $this->tecnico(localizacaoHaMinutos: null);

        $this->artisan('vendors:avisar-online-sem-localizacao')->assertSuccessful();

        Notification::assertSentTo($vendor->user, OnlineSemLocalizacaoNotification::class);
    }

    public function test_com_localizacao_recente_nao_e_avisado(): void
    {
        $vendor = $this->tecnico(localizacaoHaMinutos: 20);

        $this->artisan('vendors:avisar-online-sem-localizacao')->assertSuccessful();

        Notification::assertNotSentTo($vendor->user, OnlineSemLocalizacaoNotification::class);
    }

    public function test_offline_nao_e_avisado(): void
    {
        $vendor = $this->tecnico(localizacaoHaMinutos: 300, status: StatusVendor::OFFLINE);

        $this->artisan('vendors:avisar-online-sem-localizacao')->assertSuccessful();

        Notification::assertNotSentTo($vendor->user, OnlineSemLocalizacaoNotification::class);
    }

    public function test_um_aviso_so_a_cada_seis_horas(): void
    {
        $vendor = $this->tecnico(localizacaoHaMinutos: 120);

        $this->artisan('vendors:avisar-online-sem-localizacao');
        $this->artisan('vendors:avisar-online-sem-localizacao');

        Notification::assertSentToTimes($vendor->user, OnlineSemLocalizacaoNotification::class, 1);
    }

    public function test_de_noite_ninguem_e_avisado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 23:30', 'Europe/Lisbon')->utc());
        $vendor = $this->tecnico(localizacaoHaMinutos: 300);

        $this->artisan('vendors:avisar-online-sem-localizacao')->assertSuccessful();

        Notification::assertNotSentTo($vendor->user, OnlineSemLocalizacaoNotification::class);
    }

    public function test_a_meio_de_um_servico_nao_e_avisado(): void
    {
        $vendor = $this->tecnico(localizacaoHaMinutos: 120);
        Service::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => ServicesType::factory()->create()->id,
            'status' => ServiceStatus::ARRIVED,
        ]);

        $this->artisan('vendors:avisar-online-sem-localizacao')->assertSuccessful();

        Notification::assertNotSentTo($vendor->user, OnlineSemLocalizacaoNotification::class);
    }

    public function test_quem_nao_pode_aceitar_trabalho_nao_e_avisado(): void
    {
        $vendor = $this->tecnico(localizacaoHaMinutos: 120, completo: false);

        $this->artisan('vendors:avisar-online-sem-localizacao')->assertSuccessful();

        Notification::assertNotSentTo($vendor->user, OnlineSemLocalizacaoNotification::class);
    }

    /** Técnico com o onboarding completo (ver MatchingFlowTest::makeVendor). */
    private function tecnico(?int $localizacaoHaMinutos, StatusVendor $status = StatusVendor::ONLINE, bool $completo = true): Vendor
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);

        $vendor = Vendor::factory()->create(array_merge([
            'user_id' => $user->id,
            'status' => $status,
        ], $completo ? [
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => '999999999/1',
            'at_valid' => true,
            'at_validated_at' => now(),
        ] : [
            // Sem a AT validada não se aceita trabalho (can_accept_service).
            'at_user' => null,
            'at_valid' => false,
        ]));

        Address::create([
            'user_id' => $user->id,
            'name' => 'Casa',
            'street_name' => 'Rua de Exemplo',
            'street_number' => '1',
            'postal_code' => '1100-000',
            'city' => 'Lisboa',
            'municipality' => 'Lisboa',
            'state' => 'Lisboa',
            'country' => 'Portugal',
            'latitude' => 38.71,
            'longitude' => -9.14,
            'main_address' => false,
            'address_type' => AddressType::SCHEDULE_ADDRESS,
        ]);

        if ($localizacaoHaMinutos !== null) {
            $location = $vendor->currentLocation()->save(new Location(['latitude' => 38.71, 'longitude' => -9.14]));
            $quando = now()->subMinutes($localizacaoHaMinutos);
            Location::whereKey($location->getKey())->update(['updated_at' => $quando, 'created_at' => $quando]);
        }

        return $vendor->fresh();
    }
}
