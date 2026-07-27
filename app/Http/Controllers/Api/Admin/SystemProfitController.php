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
        // Chaves normalizadas para string: 'admin_id' vem do JSON da coluna `meta`
        // (json_decode preserva o tipo, normalmente int), mas pluck('name', 'id')
        // devolve os valores tal como o driver PDO os dá (frequentemente string) --
        // sem normalizar, a comparação de chaves falhava silenciosamente (null).
        $adminIds = collect($transactions->items())
            ->map(fn (Transaction $t) => $t->meta['admin_id'] ?? null)
            ->filter()
            ->unique();
        $adminNames = User::whereIn('id', $adminIds)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (User $u) => [(string) $u->id => $u->name]);

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
