<?php

namespace Tests\Feature\Vendors;

use App\Models\GeneralSettings\City;
use App\Models\GeneralSettings\Gender;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O PERFIL DIZ QUANTAS CIDADES O TÉCNICO ESCOLHEU.
 *
 * O passo das cidades no "Completar perfil" não tinha sinal persistente: usava
 * uma flag de sessão que voltava a `false` sempre que o ecrã abria, e o passo
 * reaparecia como se nunca tivesse sido feito -- com as cidades já guardadas.
 * O técnico preenchia, voltava, e concluía que não tinha gravado.
 */
class CidadesNoPerfilTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['users', 'wallets', 'vendors', 'cities', 'vendor_available_cities'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
    }

    private function tecnico(): Vendor
    {
        $user = User::factory()->create();

        return Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id]);
    }

    private function cidade(string $nome): City
    {
        return City::create(['name' => $nome, 'district' => 'Lisboa', 'suggested' => true, 'active' => true]);
    }

    public function test_sem_cidades_o_perfil_diz_zero(): void
    {
        $v = $this->tecnico();

        $this->actingAs($v->user, 'api')->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.available_cities_count', 0);
    }

    /** O que sai do perfil é o que foi guardado pelo endpoint das cidades. */
    public function test_depois_de_guardar_o_perfil_conta_as_cidades(): void
    {
        $v = $this->tecnico();
        $ids = collect(['Lisboa', 'Sintra', 'Cascais', 'Oeiras'])->map(fn ($n) => $this->cidade($n)->id)->all();

        $this->actingAs($v->user, 'api')
            ->postJson('/api/v1/vendor/cities', ['available_city_ids' => $ids])
            ->assertOk();

        $this->actingAs($v->user, 'api')->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.available_cities_count', 4);
    }

    /** E o GET das cidades devolve as mesmas, para o ecrã aparecer preenchido. */
    public function test_o_get_das_cidades_devolve_o_que_foi_guardado(): void
    {
        $v = $this->tecnico();
        $ids = collect(['Lisboa', 'Sintra', 'Cascais'])->map(fn ($n) => $this->cidade($n)->id)->all();

        $this->actingAs($v->user, 'api')
            ->postJson('/api/v1/vendor/cities', ['available_city_ids' => $ids])
            ->assertOk();

        $devolvidas = $this->actingAs($v->user, 'api')->getJson('/api/v1/vendor/cities')
            ->assertOk()
            ->json('data.selected.available_city_ids');

        $this->assertEqualsCanonicalizing($ids, $devolvidas);
    }
}
