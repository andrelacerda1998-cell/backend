<?php

namespace Tests\Feature\Vendors;

use App\Models\GeneralSettings\Gender;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\AddressService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * MORADA QUE A GOOGLE NÃO RECONHECE: 400, NÃO 500.
 *
 * No ramo "não foi possível geocodificar", o controlador registava a resposta
 * da Google com `Log::info('address', $geoAddress)` -- e nesse ramo ela pode
 * ser `null` ou `''`. O `Log::info` exige um array: rebentava com TypeError e o
 * técnico levava um 500 em vez do 400 "morada inválida".
 */
class MoradaNaoReconhecidaTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['users', 'wallets', 'vendors', 'addresses'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
    }

    private function morada(): array
    {
        return [
            'street_name' => 'Rua Que Nao Existe',
            'street_number' => '999',
            'postal_code' => '0000-000',
            'city' => 'Lado Nenhum',
            'country' => 'Portugal',
        ];
    }

    /** @dataProvider respostasDaGoogleSemMorada */
    public function test_morada_nao_reconhecida_devolve_400(mixed $resposta): void
    {
        $this->mock(AddressService::class, function ($m) use ($resposta) {
            $m->shouldReceive('getCoordinates')->andReturn($resposta);
        });

        $user = User::factory()->create();
        Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/vendor/address', $this->morada())
            ->assertStatus(400);
    }

    public static function respostasDaGoogleSemMorada(): array
    {
        return [
            'null (pedido recusado)' => [null],
            'texto vazio (CouldNotGeocode)' => [''],
            'array sem coordenadas' => [['address_components' => []]],
        ];
    }
}
