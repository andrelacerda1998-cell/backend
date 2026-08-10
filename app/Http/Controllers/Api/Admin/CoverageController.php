<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\AllowedZone;
use App\Models\GeneralSettings\SurveyCity;
use App\Models\Vendor;

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

        return ApiSuccessResponse::make([
            'open' => $openZones->map($this->presentZone(...))->all(),
            'candidate' => $candidateCities->map($this->presentCity(...))->all(),
        ]);
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
