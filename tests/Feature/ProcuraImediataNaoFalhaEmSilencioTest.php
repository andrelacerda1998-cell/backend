<?php

namespace Tests\Feature;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Customer\Services\VendorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Scout\Builder;
use Tests\TestCase;

/**
 * A procura imediata não pode devolver zero sem se saber porquê.
 *
 * O índice de pesquisa era a única resposta: uma lista vazia saía como
 * resultado final, com HTTP 200, e a app mostrava "não há técnicos
 * disponíveis" — indistinguível de uma avaria. Foi assim que a procura
 * imediata devolveu zero em produção durante dias, em todo o país, enquanto
 * a agendada — que já tinha recurso à base de dados — devolvia técnicos para
 * as mesmas coordenadas.
 *
 * Estes testes correm com o motor de pesquisa `null`, que devolve sempre
 * vazio: é exactamente o cenário do índice que não sabe de ninguém.
 */
class ProcuraImediataNaoFalhaEmSilencioTest extends TestCase
{
    use RefreshDatabase;

    /** Rua Augusta, Lisboa. */
    private const LAT = 38.7108;

    private const LNG = -9.1370;

    protected function setUp(): void
    {
        parent::setUp();

        // Índice que não conhece ninguém, sem precisar de um Meilisearch.
        config(['scout.driver' => 'null']);
        config(['services.request.mock_location' => false]);
    }

    private function tipoDeServico(): ServicesType
    {
        return ServicesType::factory()->create();
    }

    private function tecnico(
        ServicesType $tipo,
        StatusVendor $estado = StatusVendor::ONLINE,
        ?float $lat = self::LAT,
        ?float $lng = self::LNG,
        int $minutosDesdeOPing = 1,
    ): Vendor {
        $vendor = Vendor::factory()->create([
            'user_id' => User::factory()->create(['is_test' => false]),
            'status' => $estado,
        ]);
        $vendor->servicesTypes()->attach($tipo->id);

        if ($lat !== null) {
            // `vendor_id` está fora do $fillable do Location: passa pela relação.
            $localizacao = $vendor->currentLocation()->create([
                'latitude' => $lat,
                'longitude' => $lng,
            ]);
            // `updated_at` é o que o filtro dos 60 minutos lê.
            $localizacao->forceFill(['updated_at' => now()->subMinutes($minutosDesdeOPing)])->saveQuietly();
        }

        return $vendor->fresh();
    }

    private function procurar(ServicesType $tipo): Collection
    {
        return collect(
            app(VendorSearchService::class)
                ->search(new AddressCoordinatesDTO(self::LAT, self::LNG), $tipo)
        );
    }

    public function test_indice_vazio_nao_esconde_um_tecnico_que_existe(): void
    {
        $tipo = $this->tipoDeServico();
        $tecnico = $this->tecnico($tipo);

        $encontrados = $this->procurar($tipo);

        $this->assertCount(1, $encontrados, 'Com o índice a dizer zero, a base de dados tem de responder.');
        $this->assertSame($tecnico->id, $encontrados->first()->id);
    }

    public function test_o_recurso_nao_oferece_um_tecnico_offline(): void
    {
        // Um recurso mais largo do que o índice seria pior do que devolver
        // zero: mandava para casa do cliente alguém que não está a trabalhar.
        $tipo = $this->tipoDeServico();
        $this->tecnico($tipo, estado: StatusVendor::OFFLINE);

        $this->assertCount(0, $this->procurar($tipo));
    }

    public function test_o_recurso_nao_oferece_um_tecnico_com_localizacao_velha(): void
    {
        $tipo = $this->tipoDeServico();
        $this->tecnico($tipo, minutosDesdeOPing: 61);

        $this->assertCount(0, $this->procurar($tipo));
    }

    public function test_o_recurso_nao_oferece_um_tecnico_fora_do_raio(): void
    {
        // Faro, a mais de 250 km de Lisboa.
        $tipo = $this->tipoDeServico();
        $this->tecnico($tipo, lat: 37.0194, lng: -7.9304);

        $this->assertCount(0, $this->procurar($tipo));
    }

    public function test_o_recurso_nao_oferece_um_tecnico_de_outra_especialidade(): void
    {
        $tipo = $this->tipoDeServico();
        $this->tecnico($this->tipoDeServico());

        $this->assertCount(0, $this->procurar($tipo));
    }

    public function test_o_recurso_ordena_pelo_mais_proximo(): void
    {
        $tipo = $this->tipoDeServico();
        // ~11 km e ~2 km da Rua Augusta.
        $longe = $this->tecnico($tipo, lat: 38.8100, lng: self::LNG);
        $perto = $this->tecnico($tipo, lat: 38.7290, lng: self::LNG);

        $encontrados = $this->procurar($tipo);

        $this->assertSame([$perto->id, $longe->id], $encontrados->pluck('id')->all());
    }

    public function test_indice_a_rebentar_cai_para_a_base_de_dados(): void
    {
        $tipo = $this->tipoDeServico();
        $tecnico = $this->tecnico($tipo);

        $servico = new class extends VendorSearchService
        {
            protected function getVendors(): Builder
            {
                throw new \RuntimeException('Meilisearch em baixo');
            }
        };

        $encontrados = collect($servico->search(new AddressCoordinatesDTO(self::LAT, self::LNG), $tipo));

        $this->assertCount(1, $encontrados, 'Um índice em baixo não pode deixar o cliente sem técnicos.');
        $this->assertSame($tecnico->id, $encontrados->first()->id);
    }
}
