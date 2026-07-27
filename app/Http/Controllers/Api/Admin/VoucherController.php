<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreVoucherRequest;
use App\Http\Requests\Api\Admin\UpdateVoucherRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Voucher;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = Voucher::query()->withCount('usages')->latest('created_at');

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'like', "%{$search}%");
        }

        $vouchers = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($vouchers->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $vouchers->currentPage(),
                'last_page' => $vouchers->lastPage(),
                'per_page' => $vouchers->perPage(),
                'total' => $vouchers->total(),
            ],
        ]);
    }

    public function store(StoreVoucherRequest $request): ApiSuccessResponse
    {
        $voucher = Voucher::create($request->validated());

        return ApiSuccessResponse::make($this->present($voucher), statusCode: 201);
    }

    public function show(Voucher $voucher): ApiSuccessResponse
    {
        $voucher->loadCount('usages');

        return ApiSuccessResponse::make($this->present($voucher));
    }

    public function update(UpdateVoucherRequest $request, Voucher $voucher): ApiSuccessResponse
    {
        $voucher->update($request->validated());
        $voucher->loadCount('usages');

        return ApiSuccessResponse::make($this->present($voucher));
    }

    public function destroy(Voucher $voucher): ApiSuccessResponse
    {
        $voucher->delete();

        return ApiSuccessResponse::make();
    }

    private function present(Voucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'name' => $voucher->name,
            'start_date' => $voucher->start_date?->toDateString(),
            'end_date' => $voucher->end_date?->toDateString(),
            'max_uses' => $voucher->max_uses,
            'discount_percentage' => $voucher->discount_percentage,
            'valid_services' => $voucher->valid_services ?? [],
            'is_active' => $voucher->is_active,
            'is_valid' => $voucher->isValid(),
            'usages_count' => $voucher->usages_count ?? 0,
            'created_at' => $voucher->created_at?->toIso8601String(),
        ];
    }
}
