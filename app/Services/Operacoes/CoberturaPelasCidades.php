<?php

namespace App\Services\Operacoes;

use App\Models\GeneralSettings\City;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quem recebe pedidos numa cidade, pelas cidades que os técnicos escolhem na app.
 *
 * É a área de trabalho do matching (VendorRankingService::naAreaDeTrabalho). A
 * app grava as cidades em `vendor_available_cities` (por /vendor/cities), e um
 * pedido chega a quem tem a morada dentro de uma delas: centro e raio da
 * cidade, ou o nome enquanto a cidade não tiver coordenadas (City::contem). A
 * regra é a mesma função, não uma cópia dela.
 *
 * As Zonas e a Cobertura contavam o questionário antigo (`vendor_allowed_zones`),
 * que a app deixou de mostrar. Diziam "ninguém" em zonas onde havia técnicos.
 *
 * Uma zona é uma cidade, não uma morada: conta-se quem receberia um pedido no
 * CENTRO dela, o da cidade do catálogo com o mesmo nome. Num concelho grande,
 * uma morada longe do centro pode chegar a mais gente. Cada contagem diz como
 * foi feita:
 *
 *  - `raio`: a cidade tem coordenadas. Vale o raio das cidades de cada técnico
 *    (e o nome, nas que ainda não as têm — como no matching);
 *  - `nome`: está no catálogo sem coordenadas (falta o `cities:geocode`). Só
 *    conta quem escolheu uma cidade com o mesmo nome;
 *  - `fora_do_catalogo`: nenhum técnico a pode escolher na app. Dá zero.
 *
 * Fica de fora, de propósito:
 *
 *  - quem não escolheu cidades. No matching não tem restrição (recebe de onde
 *    calhar, até ao raio máximo da posição dele), e contá-lo em todas as
 *    cidades inventava oferta. Vai à parte, em `semCidades()`;
 *  - o recurso a 30 km (RECURSO_FORA_DA_AREA_KM), que depende de onde o técnico
 *    está no momento do pedido;
 *  - técnicos suspensos e contas de teste.
 */
final class CoberturaPelasCidades
{
    public const PELO_RAIO = 'raio';

    public const PELO_NOME = 'nome';

    public const FORA_DO_CATALOGO = 'fora_do_catalogo';

    /** @var array<int, list<int>> id da cidade => técnicos que a escolheram */
    private array $tecnicosPorCidade;

    /** @var Collection<int, City> as cidades que alguém escolheu */
    private Collection $escolhidas;

    /** @var array<string, list<City>> o catálogo inteiro, pelo nome normalizado */
    private array $catalogo;

    public function __construct(private readonly bool $testes = false)
    {
        $this->tecnicosPorCidade = $this->tecnicos()
            ->join('vendor_available_cities', 'vendor_available_cities.vendor_id', '=', 'vendors.id')
            ->get(['vendor_available_cities.city_id', 'vendors.id as vendor_id'])
            ->groupBy('city_id')
            ->map(fn (Collection $linhas) => $linhas->pluck('vendor_id')->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->all();

        $cidades = City::query()->get();

        // Não `only()`: na coleção do Eloquent renumera as chaves.
        $this->escolhidas = $cidades->filter(fn (City $c) => isset($this->tecnicosPorCidade[$c->id]))->values();
        $this->catalogo = $cidades
            ->groupBy(fn (City $c) => City::normalizar((string) $c->name))
            ->map(fn (Collection $mesmoNome) => $mesmoNome->values()->all())
            ->all();
    }

    /**
     * Quem recebe um pedido no centro desta cidade.
     *
     * @return array{tecnicos: list<int>, contagem: string}
     */
    public function naCidade(string $nome, ?string $distrito = null): array
    {
        $centro = $this->doCatalogo($nome, $distrito);

        if ($centro === null) {
            return ['tecnicos' => [], 'contagem' => self::FORA_DO_CATALOGO];
        }

        $comCoordenadas = $centro->latitude !== null && $centro->longitude !== null;
        $alvo = City::normalizar($nome);
        $tecnicos = [];

        foreach ($this->escolhidas as $cidade) {
            $cobre = $comCoordenadas
                ? $cidade->contem($centro->latitude, $centro->longitude, $nome)
                : City::normalizar((string) $cidade->name) === $alvo;

            if ($cobre) {
                array_push($tecnicos, ...$this->tecnicosPorCidade[$cidade->id]);
            }
        }

        sort($tecnicos);

        return [
            'tecnicos' => array_values(array_unique($tecnicos)),
            'contagem' => $comCoordenadas ? self::PELO_RAIO : self::PELO_NOME,
        ];
    }

    /**
     * Os técnicos que não escolheram cidade nenhuma.
     *
     * @return list<int>
     */
    public function semCidades(): array
    {
        return $this->tecnicos()
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')
                ->from('vendor_available_cities')
                ->whereColumn('vendor_available_cities.vendor_id', 'vendors.id'))
            ->orderBy('vendors.id')
            ->pluck('vendors.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Os técnicos que contam: os do lado certo (reais ou de teste), sem os suspensos. */
    private function tecnicos(): Builder
    {
        return DB::table('vendors')
            ->join('users', 'users.id', '=', 'vendors.user_id')
            ->where('users.is_test', $this->testes)
            ->whereNull('users.deleted_at')
            ->whereNull('vendors.deleted_at');
    }

    /** A cidade do catálogo com este nome; com dois iguais, a do mesmo distrito. */
    private function doCatalogo(string $nome, ?string $distrito): ?City
    {
        $mesmoNome = $this->catalogo[City::normalizar($nome)] ?? [];

        if ($mesmoNome === []) {
            return null;
        }

        if ($distrito !== null && trim($distrito) !== '') {
            foreach ($mesmoNome as $cidade) {
                if (City::normalizar((string) $cidade->district) === City::normalizar($distrito)) {
                    return $cidade;
                }
            }
        }

        return $mesmoNome[0];
    }
}
