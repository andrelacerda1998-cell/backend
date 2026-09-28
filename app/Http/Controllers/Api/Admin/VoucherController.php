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

        $query = Voucher::query()
            ->withCount('usages')
            ->withCount('services')
            ->withSum('services as discount_total_cents', 'discount_amount')
            ->latest('created_at');

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
        $this->comTotais($voucher);

        return ApiSuccessResponse::make($this->present($voucher));
    }

    public function update(UpdateVoucherRequest $request, Voucher $voucher): ApiSuccessResponse
    {
        $voucher->update($request->validated());
        $this->comTotais($voucher);

        return ApiSuccessResponse::make($this->present($voucher));
    }

    public function destroy(Voucher $voucher): ApiSuccessResponse
    {
        $voucher->delete();

        return ApiSuccessResponse::make();
    }

    /** Os mesmos agregados que o index traz, para uma so instancia. */
    private function comTotais(Voucher $voucher): void
    {
        $voucher->loadCount('usages');
        $voucher->loadCount('services');
        $voucher->loadSum('services as discount_total_cents', 'discount_amount');
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
            'services_count' => $voucher->services_count ?? 0,
            /*
             * Quanto desconto este voucher ja deu, EM CENTIMOS.
             *
             * O nome diz a unidade de proposito: `services.discount_amount` e
             * inteiro em centimos, e um campo chamado so `discount_total` seria
             * lido como euros por quem o consome -- ja aconteceu com o
             * `starts_from`. Quem mostrar isto divide por 100.
             *
             * Vem de `services`, nao de `voucher_usages`: o uso regista que o
             * voucher foi aplicado, o servico e que guarda o valor abatido.
             */
            'discount_total_cents' => (int) ($voucher->discount_total_cents ?? 0),
            'created_at' => $voucher->created_at?->toIso8601String(),
        ];
    }
}
