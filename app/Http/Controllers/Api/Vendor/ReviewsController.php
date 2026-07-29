<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Http\Request;

class ReviewsController extends Controller
{
    /** Avaliações e comentários dos clientes ao vendor autenticado. */
    public function __invoke(Request $request)
    {
        $vendor = $request->user()->vendor;

        $reviews = $vendor->services()
            ->whereNotNull('rating_by_customer')
            ->with(['serviceType', 'customerUser'])
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'rating' => (int) $s->rating_by_customer,
                'comment' => $s->rating_comment_by_customer,
                'service_type' => $s->serviceType?->name,
                'customer_name' => $s->customerUser?->first_name,
                'date' => $s->updated_at?->toIso8601String(),
            ]);

        $avg = $vendor->services()->whereNotNull('rating_by_customer')->avg('rating_by_customer');

        return new ApiSuccessResponse([
            'average' => $avg !== null ? round((float) $avg, 1) : null,
            'total' => $reviews->count(),
            'reviews' => $reviews,
        ]);
    }
}
