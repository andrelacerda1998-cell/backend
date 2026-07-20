<?php

namespace App\Http\Controllers\Api\Vendor\Wallet;

use App\Exceptions\Api\User\WrongApp;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use Illuminate\Http\Request;

class WalletHistoryController extends Controller
{
    public function __invoke(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user->isVendor()) {
                throw new WrongApp;
            }

            $transactions = $user->vendor->transactions();
            $transactionsLength = $transactions->count();

            $limit = 10;
            $offSet = request('offset') ?? 0;
            $have_more = $transactionsLength > ($offSet + $limit);

            // NOTA: mantém-se limit($offSet + $limit) (linhas cumulativas 0..offset+limit) de
            // PROPÓSITO. A app-vendor (wallet/index.tsx) faz setTransactions(response) — SUBSTITUI a
            // lista — com offset = transactions.length. Passar a offset/limit verdadeiro faria o
            // "carregar mais" devolver só 10 linhas e a app perder as anteriores. A correção da
            // paginação exige alterar a app (append em vez de replace) e sair coordenada com um
            // release — ver follow-up 2026-07-17-wallet-history-paginacao-cumulativa-app-coupled.md.
            $transactions = $transactions
                                ->limit($offSet + $limit)
                                ->orderBy('created_at', 'desc')
                                ->get();

            // getting the server to send the customer avatar to the vendor


            $transactions = $transactions
                ->transform(function ($transaction) {
                    // Só resolver o serviço quando a transação é MESMO de um serviço.
                    // Transferências/reembolsos gravam meta['class'] = User/Vendor com o
                    // respetivo id; sem este filtro, um meta['id'] que coincida com um id de
                    // Service qualquer expunha name/phone/email/avatar de um cliente alheio.
                    $service = (($transaction->meta['class'] ?? null) === Service::class)
                        ? Service::query()->find($transaction->meta['id'] ?? null)
                        : null;

                    return [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'amount' => $transaction->amount,
                        'amount_formatted' => number_format($transaction->amount/100, 2, '.', ' '),
                        'confirmed' => $transaction->confirmed,
                        // 'meta' => $transaction->meta,
                        'service' => [
                            'id' => $service?->id,
                            'description' => $transaction->meta['description'] ?? null,
                            'admin_description' => $transaction->meta['admin_description'] ?? null,
                            'customer' => $service?->customerUser?->only([
                                'name',
                                'phone',
                                'email',
                                'avatar'
                            ]),
                        ],
                        'created_at' => $transaction->created_at,
                        'updated_at' => $transaction->updated_at,
                    ];
                });


            return new ApiSuccessResponse(compact('transactions', 'have_more'));
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
