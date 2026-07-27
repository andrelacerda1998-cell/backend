<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\User;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Http\Request;

class SystemProfitController extends Controller
{
    /**
     * GET /v1/admin/system-profit — saldo atual da wallet do sistema + transações
     * paginadas (equivalente ao que a página SystemProfit do Filament mostra).
     */
    public function index(Request $request): ApiSuccessResponse
    {
        $wallet = system_wallet();
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = $wallet->transactions()->getQuery()->latest('created_at');

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $transactions = $query->paginate($perPage);

        // Nomes de admin resolvidos num único query, em vez de N+1 dentro do map.
        // Chaves normalizadas para string por segurança (admin_id vem do JSON
        // da coluna `meta`, tipo pode variar consoante o driver).
        //
        // NÃO usar a coluna `name`: User::setNameAttribute() intercepta qualquer
        // escrita a 'name' e separa-a em first_name/last_name, mas nunca chega a
        // gravar a própria coluna `name` -- fica sempre NULL para qualquer registo
        // criado (incluindo, possivelmente, admins criados pelo form do Filament,
        // que também escreve via 'name'). first_name/last_name são fiáveis porque
        // é precisamente isso que o mutator preenche.
        $adminIds = collect($transactions->items())
            ->map(fn (Transaction $t) => $t->meta['admin_id'] ?? null)
            ->filter()
            ->unique();
        $adminNames = User::whereIn('id', $adminIds)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $u) => [
                (string) $u->id => trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: null,
            ]);

        return ApiSuccessResponse::make([
            'wallet_balance' => $wallet->balance_float,
            'items' => collect($transactions->items())->map(function (Transaction $t) use ($adminNames) {
                $adminId = $t->meta['admin_id'] ?? null;

                return [
                    'id' => $t->id,
                    'type' => $t->meta['type'] ?? $t->type,
                    'description_key' => $t->meta['admin_description'] ?? null,
                    'admin_id' => $adminId,
                    'admin_name' => $adminId !== null ? ($adminNames[(string) $adminId] ?? null) : null,
                    'amount' => $t->amount_float,
                    'created_at' => $t->created_at?->toIso8601String(),
                ];
            })->all(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }
}
