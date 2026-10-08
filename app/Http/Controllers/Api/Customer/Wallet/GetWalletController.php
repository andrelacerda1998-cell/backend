<?php

namespace App\Http\Controllers\Api\Customer\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet\WalletCredit;
use App\Services\Carteira\CarteiraDoCliente;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

/**
 * GET /v1/customer/wallet — a Carteira do cliente, pronta a mostrar.
 *
 * Total, as duas partes (Saldo e Crédito de convites), o que expira e quando,
 * e os movimentos das duas carteiras numa só lista, com o texto já escrito no
 * idioma do cliente — a app não tem de saber o que é um meta da Bavix.
 */
class GetWalletController extends Controller
{
    public function __invoke(Request $request, CarteiraDoCliente $carteira): ApiSuccessResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        $saldo = $carteira->saldoDisponivel($user);
        $convites = $carteira->convitesDisponivel($user);

        $aExpirar = WalletCredit::where('user_id', $user->id)
            ->usable()
            ->orderBy('expires_at')
            ->get(['remaining', 'expires_at'])
            ->map(fn (WalletCredit $c) => [
                'amount' => $c->remaining,
                'amount_formated' => $this->euros($c->remaining),
                'expires_at' => $c->expires_at->toIso8601String(),
            ])
            ->values();

        $carteiras = array_filter([
            $user->wallet?->getKey() => 'saldo',
            $carteira->carteiraConvites($user)?->getKey() => 'convites',
        ]);

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);
        $movimentos = Transaction::query()
            ->whereIn('wallet_id', array_keys($carteiras))
            ->where('confirmed', true)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $servicos = Service::with('serviceType')
            ->whereIn('id', collect($movimentos->items())->map(fn ($t) => $t->meta['service_id'] ?? null)->filter()->unique())
            ->get()
            ->keyBy('id');

        return ApiSuccessResponse::make([
            'total' => $saldo + $convites,
            'total_formated' => $this->euros($saldo + $convites),
            'saldo' => $saldo,
            'saldo_formated' => $this->euros($saldo),
            'convites' => $convites,
            'convites_formated' => $this->euros($convites),
            'convites_a_expirar' => $aExpirar,
            'movimentos' => [
                'items' => collect($movimentos->items())
                    ->map(fn (Transaction $t) => $this->movimento($t, $carteiras[$t->wallet_id] ?? 'saldo', $servicos))
                    ->all(),
                'meta' => [
                    'current_page' => $movimentos->currentPage(),
                    'last_page' => $movimentos->lastPage(),
                    'per_page' => $movimentos->perPage(),
                    'total' => $movimentos->total(),
                ],
            ],
        ]);
    }

    private function movimento(Transaction $t, string $parte, $servicos): array
    {
        $meta = $t->meta ?? [];
        $valor = abs((int) $t->amount);
        $entrada = $t->type === Transaction::TYPE_DEPOSIT;
        $servico = isset($meta['service_id']) ? $servicos->get($meta['service_id']) : null;
        $nomeServico = $servico?->serviceType?->getTranslation('name', app()->getLocale()) ?? '';

        $descricao = match (true) {
            ($meta['type'] ?? null) === 'service_payment' => $nomeServico
                ? __('carteira.movimentos.usado_em', ['servico' => $nomeServico])
                : __('carteira.movimentos.usado'),
            in_array($meta['type'] ?? null, ['service_refund', 'internal/services.transactions_type.refund'], true) => __('carteira.movimentos.reembolso'),
            ($meta['type'] ?? null) === 'referral_credit' => Lang::has('carteira.movimentos.credito_'.($meta['reason'] ?? ''))
                ? __('carteira.movimentos.credito_'.$meta['reason'])
                : __('carteira.movimentos.credito_convite'),
            ($meta['type'] ?? null) === 'referral_credit_expired' => __('carteira.movimentos.expirado'),
            // Créditos e débitos feitos à mão no backoffice trazem a justificação escrita.
            in_array($meta['type'] ?? null, ['Credit', 'Debit'], true) && ! empty($meta['description']) => (string) $meta['description'],
            default => $entrada ? __('carteira.movimentos.entrada') : __('carteira.movimentos.saida'),
        };

        return [
            'id' => $t->getKey(),
            'tipo' => $entrada ? 'entrada' : 'saida',
            'parte' => $parte,
            'valor' => $valor,
            'valor_formated' => $this->euros($valor),
            'descricao' => $descricao,
            'service_id' => $servico?->getKey(),
            'data' => $t->created_at?->toIso8601String(),
        ];
    }

    private function euros(int $centimos): string
    {
        return number_format($centimos / 100, 2, '.', ' ');
    }
}
