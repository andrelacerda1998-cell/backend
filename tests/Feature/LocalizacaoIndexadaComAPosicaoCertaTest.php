<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Vendor\Location\UpdateLocationController;
use App\Http\Requests\Api\Vendor\StoreLocationRequest;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Tests\TestCase;

/**
 * O que vai para o índice é a posição que o técnico acabou de mandar.
 *
 * O controlador lê `$vendor->currentLocation` no início, para o teste do
 * dispositivo, e essa leitura fica em cache. Depois escrevia a posição nova e
 * chamava `searchable()` — que lê a MESMA relação em cache. Resultado:
 *
 *  - no primeiro ping de sempre, a relação estava a null, e o técnico entrava
 *    no índice em `_geo {0, 0}` com `geoTime` nulo;
 *  - nos pings seguintes, o índice ficava sempre um ping atrasado.
 *
 * 0,0 é no Golfo da Guiné e um `geoTime` nulo não entra em janela nenhuma, por
 * isso a procura imediata — que filtra por raio e pelos últimos 60 minutos —
 * não via esse técnico. Sem erro, sem aviso: só não aparecia.
 *
 * Não chega verificar a base de dados: a base sempre esteve certa. O que se
 * tem de prender é o que o motor de pesquisa RECEBE, no instante em que
 * recebe — daí o motor falso que guarda o documento.
 */
class LocalizacaoIndexadaComAPosicaoCertaTest extends TestCase
{
    use RefreshDatabase;

    /** Documentos entregues ao motor de pesquisa, por ordem. */
    private array $indexado = [];

    protected function setUp(): void
    {
        parent::setUp();

        $captura = function (array $documento) {
            $this->indexado[] = $documento;
        };

        $motor = new class($captura) extends NullEngine
        {
            public function __construct(private $captura) {}

            public function update($models)
            {
                foreach ($models as $model) {
                    ($this->captura)($model->toSearchableArray());
                }
            }
        };

        app(EngineManager::class)->extend('captura', fn () => $motor);
        config(['scout.driver' => 'captura', 'scout.queue' => false]);
    }

    private function pingar(Vendor $vendor, float $lat, float $lng): void
    {
        $this->actingAs($vendor->user, 'api');

        $request = StoreLocationRequest::create('/', 'POST', [
            'latitude' => $lat,
            'longitude' => $lng,
            'device_id' => 'dispositivo-de-teste',
        ]);
        $request->setContainer(app());

        (new UpdateLocationController)($request);
    }

    private function tecnico(): Vendor
    {
        $vendor = Vendor::factory()->create(['user_id' => User::factory()])->fresh();

        // Criar o técnico já o manda para o índice (o Scout indexa ao gravar),
        // e nessa altura ele ainda não tem posição nenhuma. Esse documento não
        // é o que está em causa aqui: o que se mede é o que o PING escreve.
        $this->indexado = [];

        return $vendor;
    }

    public function test_o_primeiro_ping_nao_indexa_o_tecnico_em_0_0(): void
    {
        $this->pingar($this->tecnico(), 38.7108, -9.1370);

        $this->assertNotEmpty($this->indexado, 'O ping tem de mandar o técnico para o índice.');
        $geo = $this->indexado[0]['_geo'];

        $this->assertEqualsWithDelta(38.7108, (float) $geo['lat'], 0.0001);
        $this->assertEqualsWithDelta(-9.1370, (float) $geo['lng'], 0.0001);
    }

    public function test_o_primeiro_ping_indexa_um_geotime(): void
    {
        // Com `geoTime` nulo o técnico nunca entra na janela dos 60 minutos,
        // por muito recente que a posição esteja na base de dados.
        $this->pingar($this->tecnico(), 38.7108, -9.1370);

        $this->assertNotNull($this->indexado[0]['geoTime'], 'Sem geoTime, a procura imediata nunca o encontra.');
    }

    public function test_o_indice_nao_fica_um_ping_atrasado(): void
    {
        $vendor = $this->tecnico();

        $this->pingar($vendor, 38.7108, -9.1370);   // Rua Augusta
        $this->pingar($vendor->fresh(), 38.7223, -9.1393);   // Restauradores

        $ultimo = end($this->indexado);

        $this->assertEqualsWithDelta(38.7223, (float) $ultimo['_geo']['lat'], 0.0001,
            'O índice tem de ficar com a posição do último ping, não com a do anterior.');
    }
}
