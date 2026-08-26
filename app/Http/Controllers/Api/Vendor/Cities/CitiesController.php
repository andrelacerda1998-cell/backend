<?php

namespace App\Http\Controllers\Api\Vendor\Cities;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Cities\SaveCitiesRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\City;
use Illuminate\Support\Facades\DB;

class CitiesController extends Controller
{
    /**
     * Catalogo completo (a app faz a pesquisa/autocomplete do lado do cliente
     * -- sao poucas centenas de cidades) mais o que este tecnico ja guardou.
     */
    public function index()
    {
        try {
            $vendor = auth()->user()->vendor;

            $cities = City::query()
                ->orderBy('name')
                ->get(['id', 'name', 'district', 'suggested', 'active'])
                ->map(fn (City $c) => [
                    'id'        => $c->id,
                    'name'      => $c->name,
                    'district'  => $c->district,
                    'suggested' => $c->suggested,
                    'active'    => $c->active,
                ]);

            return new ApiSuccessResponse([
                'cities'   => $cities,
                'selected' => [
                    'available_city_ids' => $vendor->availableCities()->pluck('cities.id'),
                    'preferred_city_ids' => $vendor->preferredCities()->pluck('cities.id'),
                ],
            ]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }

    /**
     * Guarda os dois conjuntos numa transacao: available (todas onde aceita
     * trabalhar) e preferred (top 3, com a ordem em que chegam como posicao).
     */
    public function store(SaveCitiesRequest $request)
    {
        try {
            $vendor = auth()->user()->vendor;

            $available = $request->input('available_city_ids');
            $preferred = $request->input('preferred_city_ids');

            DB::transaction(function () use ($vendor, $available, $preferred) {
                $vendor->availableCities()->sync($available);

                $withPosition = [];
                foreach (array_values($preferred) as $i => $cityId) {
                    $withPosition[$cityId] = ['position' => $i + 1];
                }
                $vendor->preferredCities()->sync($withPosition);
            });

            return new ApiSuccessResponse();
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
