<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use Exception;
use Illuminate\Http\Request;

/**
 * Fotos do trabalho (antes/depois) — protegem o técnico em caso de reclamação.
 */
class ServicePhotosController extends Controller
{
    private const COLLECTIONS = ['before', 'after'];

    private function authorizeService(Service $service): bool
    {
        return $service->vendor_id === auth()->user()->vendor?->id;
    }

    public function index(Service $service)
    {
        if (! $this->authorizeService($service)) {
            return new ApiErrorResponse(new Exception, 'Service not found', 404);
        }

        $photos = [];
        foreach (self::COLLECTIONS as $collection) {
            $photos[$collection] = $service->getMedia($collection)
                ->map(fn ($m) => [
                    'id' => $m->id,
                    // Por media — getFirstTemporaryUrl() devolveria sempre a 1.ª foto da coleção.
                    'url' => $m->getTemporaryUrl(now()->addMinutes(30)),
                ])->values();
        }

        return new ApiSuccessResponse(['photos' => $photos]);
    }

    public function store(Request $request, Service $service)
    {
        if (! $this->authorizeService($service)) {
            return new ApiErrorResponse(new Exception, 'Service not found', 404);
        }

        $validated = $request->validate([
            'collection' => 'required|in:before,after',
            'photo' => 'required|file|mimes:jpg,jpeg,png,heic,heif,webp|max:10240',
        ]);

        $media = $service->addMediaFromRequest('photo')->toMediaCollection($validated['collection']);

        return new ApiSuccessResponse([
            'id' => $media->id,
            'url' => $media->getTemporaryUrl(now()->addMinutes(30)),
        ]);
    }
}
