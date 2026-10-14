<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\AllowedZone;
use App\Models\GeneralSettings\SurveyCity;
use App\Models\Vendor;
use App\Services\Operacoes\CoberturaPelasCidades;
use App\Services\Operacoes\PodemTrabalhar;
use Illuminate\Support\Collection;

/**
 * Cobertura geográfica por técnico — não tem equivalente direto no Filament
 * (lá só existia cidade a cidade, dentro de cada AllowedZoneResource/
 * SurveyCityResource, via VendorsRelationManager/VotersRelationManager,
 * sem vista agregada). Pedido explícito do utilizador (2026-08-10): "os
 * técnicos inserem na app onde podem/querer atuar, [queremos] uma análise
 * das diversas áreas e consoante técnicos".
 *
 * Junta os dois sinais que já existem hoje (escritos pela própria app do
 * técnico em POST /vendor/survey/vote, ver SurveyCitiesController):
 *
 * - 'open': zonas onde a Piquet já está aberta (tabela allowed_zone) e os
 *   técnicos que marcaram que atuam lá (vendor_allowed_zones). É cobertura
 *   REAL -- assim que o técnico marca, fica logo associado, sem aprovação
 *   de admin (ver nota em SurveyCitiesController@vote).
 * - 'candidate': cidades ainda não abertas (survey_cities) onde técnicos
 *   manifestaram interesse (vendor_city_votes). É sinal de PROCURA para
 *   decidir onde abrir a seguir -- só entram cidades com 'active' true ou
 *   false consoante ainda aceitam votos, ambas mostradas (o admin vê o
 *   estado em 'active').
 *
 * Sem paginação/pesquisa propositadamente: é um mapa de cobertura para
 * consulta visual, não uma listagem de gestão -- o número de zonas/cidades
 * é pequeno (dezenas, não milhares).
 *
 * O questionário (POST /vendor/survey/vote) MORREU: a app grava agora as
 * cidades por /vendor/cities (`vendor_available_cities`), e é por elas que o
 * matching decide a quem chega um pedido. `technicians` e `candidate`
 * continuam a vir do questionário, para o backoffice que ainda os lê. O que
 * vale hoje vai em `nas_cidades` de cada zona (CoberturaPelasCidades, a regra
 * do matching) e em `sem_cidades`, quem não escolheu cidade nenhuma.
 */
class CoverageController extends Controller
{
    public function index(): ApiSuccessResponse
    {
        $openZones = AllowedZone::query()
            ->with(['vendors' => fn ($q) => $q->with('user')])
            ->orderBy('city')
            ->get();

        $candidateCities = SurveyCity::query()
            ->with(['voters' => fn ($q) => $q->with('user')])
            ->orderBy('city')
            ->get();

        $nasCidades = new CoberturaPelasCidades;
        $podem = PodemTrabalhar::ids()->flip();

        $porZona = $openZones->mapWithKeys(fn (AllowedZone $zone) => [
            $zone->id => $nasCidades->naCidade($zone->city, $zone->district),
        ]);
        $semCidades = $nasCidades->semCidades();

        // Cada técnico uma vez, mesmo que cubra várias zonas.
        $tecnicos = Vendor::query()
            ->whereIn('id', $porZona->pluck('tecnicos')->flatten()->unique()->all())
            ->get()
            ->keyBy('id');

        return ApiSuccessResponse::make([
            'open' => $openZones->map(fn (AllowedZone $zone) => $this->presentZone($zone) + [
                'nas_cidades' => $this->presentNasCidades($porZona[$zone->id], $tecnicos, $podem),
            ])->all(),
            'candidate' => $candidateCities->map($this->presentCity(...))->all(),
            'sem_cidades' => [
                'tecnicos' => count($semCidades),
                'podem_trabalhar' => count(array_filter($semCidades, fn (int $id) => $podem->has($id))),
            ],
        ]);
    }

    /**
     * Quem recebe pedidos na zona, pelas cidades que escolheu na app: os que
     * já podem trabalhar primeiro, depois pelo nome.
     *
     * @param  array{tecnicos: list<int>, contagem: string}  $quem
     */
    private function presentNasCidades(array $quem, Collection $tecnicos, Collection $podem): array
    {
        $lista = collect($quem['tecnicos'])
            ->map(fn (int $id) => $tecnicos->get($id))
            ->filter()
            ->map(fn (Vendor $vendor) => $this->presentVendor($vendor) + ['podem_trabalhar' => $podem->has($vendor->id)])
            ->sortBy([['podem_trabalhar', 'desc'], fn (array $a, array $b) => strnatcasecmp((string) $a['name'], (string) $b['name'])])
            ->values();

        return [
            'tecnicos' => $lista->all(),
            'podem_trabalhar' => $lista->where('podem_trabalhar', true)->count(),
            'contagem' => $quem['contagem'],
        ];
    }

    private function presentZone(AllowedZone $zone): array
    {
        return [
            'id' => $zone->id,
            'city' => $zone->city,
            'district' => $zone->district,
            'technicians' => $zone->vendors->map($this->presentVendor(...))->all(),
        ];
    }

    private function presentCity(SurveyCity $city): array
    {
        return [
            'id' => $city->id,
            'city' => $city->city,
            'district' => $city->district,
            'active' => (bool) $city->active,
            'technicians' => $city->voters->map($this->presentVendor(...))->all(),
        ];
    }

    private function presentVendor(Vendor $vendor): array
    {
        $user = $vendor->user;

        return [
            'id' => $vendor->id,
            // NÃO usar vendor->name/fullName -- delegam num accessor que
            // nunca é gravado (mesma nota já usada em VendorController).
            'name' => $user ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null) : null,
            'nif' => $user?->nif,
            'email' => $user?->email,
            'phone_number' => $user?->phone_number,
            'status' => $vendor->status?->value,
        ];
    }
}
