<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Common\GooglePlacesAutocompleteRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Services\Common\GooglePlacesAutocompleteService;
use Exception;

class GooglePlacesAutocompleteController extends Controller
{
    public function __construct(
        private readonly GooglePlacesAutocompleteService $placesService
    ) {}

    public function __invoke(GooglePlacesAutocompleteRequest $request): ApiSuccessResponse|ApiErrorResponse
    {
        try {
            $predictions = $this->placesService->getAutocomplete($request->input('input'));

            if ($predictions === null) {
                throw new Exception('Could not fetch autocomplete results', 500);
            }

            return new ApiSuccessResponse(['predictions' => $predictions]);
        } catch (Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
