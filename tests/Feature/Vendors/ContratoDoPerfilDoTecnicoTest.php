<?php

namespace Tests\Feature\Vendors;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que o `/auth/me` promete à app do técnico.
 *
 * A app lê estes campos por nome. Um nome que mude, ou um campo que deixe de
 * sair, não parte nada no servidor — parte-se na app, em silêncio, e do lado
 * onde ninguém está a olhar: `vendorData?.at_required` passa a `undefined`, o
 * `=== true` dá falso, e o passo da AT desaparece para toda a gente.
 *
 * Este ficheiro é o contrato escrito. Falha aqui em vez de falhar lá.
 */
class ContratoDoPerfilDoTecnicoTest extends TestCase
{
    use RefreshDatabase;

    private function tecnico(): Vendor
    {
        $user = User::factory()->create([
            'is_test' => true,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);

        return Vendor::factory()->create([
            'user_id' => $user->id,
            'status' => StatusVendor::ONLINE,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => null,
        ]);
    }

    private function perfil(Vendor $vendor): array
    {
        return $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertSuccessful()
            ->json('data');
    }

    public function test_o_perfil_leva_os_campos_que_a_app_le(): void
    {
        $perfil = $this->perfil($this->tecnico());

        foreach ([
            'at_user',
            'at_required',
            'services_until_at_required',
            'account_blocker',
            'can_accept_service',
            'missing_documents',
            'iban',
        ] as $campo) {
            $this->assertArrayHasKey($campo, $perfil, "a app lê `$campo` pelo nome");
        }
    }

    public function test_os_tipos_sao_os_que_a_app_espera(): void
    {
        $perfil = $this->perfil($this->tecnico());

        $this->assertIsBool($perfil['at_required']);
        $this->assertIsInt($perfil['services_until_at_required']);
    }

    /** Sem serviços: não exigida, e a app tem o número para o dizer. */
    public function test_tecnico_novo_ve_tres_servicos_pela_frente(): void
    {
        $perfil = $this->perfil($this->tecnico());

        $this->assertFalse($perfil['at_required']);
        $this->assertSame(3, $perfil['services_until_at_required']);
    }

    /** Ao terceiro, o perfil passa a dizer que trava — e porquê. */
    public function test_depois_dos_tres_o_perfil_diz_que_trava(): void
    {
        $vendor = $this->tecnico();

        for ($i = 0; $i < 3; $i++) {
            Service::factory()->create([
                'vendor_id' => $vendor->id,
                'status' => ServiceStatus::CLOSED,
                'payment_status' => PaymentStatus::PAID,
            ]);
        }

        $perfil = $this->perfil($vendor->fresh());

        $this->assertTrue($perfil['at_required']);
        $this->assertSame(0, $perfil['services_until_at_required']);
        $this->assertFalse($perfil['can_accept_service']);
    }
}
