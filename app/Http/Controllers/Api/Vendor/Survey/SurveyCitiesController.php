<?php

namespace App\Http\Controllers\Api\Vendor\Survey;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Survey\VoteCitiesRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\AllowedZone;
use App\Models\GeneralSettings\SurveyCity;

class SurveyCitiesController extends Controller
{
    public function index()
    {
        try {
            $vendor = auth()->user()->vendor;
            $vendor->load(['allowedZones', 'surveyCityVotes']);

            $allowedZones = AllowedZone::query()
                ->orderBy('district')
                ->orderBy('city')
                ->get()
                ->map(fn (AllowedZone $zone) => [
                    'id'       => $zone->id,
                    'city'     => $zone->city,
                    'district' => $zone->district,
                    'active'   => true,
                    'type'     => 'allowed',
                    'voted'    => $vendor->allowedZones->contains('id', $zone->id),
                ]);

            $surveyCities = SurveyCity::query()
                ->where('active', true)
                ->orderBy('district')
                ->orderBy('city')
                ->get()
                ->map(fn (SurveyCity $city) => [
                    'id'       => $city->id,
                    'city'     => $city->city,
                    'district' => $city->district,
                    'active'   => false,
                    'type'     => 'survey',
                    'voted'    => $vendor->surveyCityVotes->contains('id', $city->id),
                ]);

            $cities = $allowedZones->concat($surveyCities)->values();

            return new ApiSuccessResponse(['cities' => $cities]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }

    public function vote(VoteCitiesRequest $request)
    {
        try {
            $vendor = auth()->user()->vendor;

            $validAllowedIds = AllowedZone::query()
                ->whereIn('id', $request->allowed_zone_ids ?? [])
                ->pluck('id')
                ->toArray();

            $validSurveyIds = SurveyCity::query()
                ->whereIn('id', $request->survey_city_ids ?? [])
                ->where('active', true)
                ->pluck('id')
                ->toArray();

            $vendor->allowedZones()->sync($validAllowedIds);
            $vendor->surveyCityVotes()->sync($validSurveyIds);

            return new ApiSuccessResponse();
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
