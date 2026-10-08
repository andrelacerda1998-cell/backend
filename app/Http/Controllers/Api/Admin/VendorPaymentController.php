<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Mail\Vendor\PaymentSentMail;
use App\Models\Service;
use App\Models\Vendor;
use App\Notifications\Vendor\PaymentSentNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Pagamentos a vendors — migrado do Filament (App\Filament\Pages\VendorPayments).
 *
 * IMPORTANTE: isto NÃO transfere dinheiro a sério. O saldo de cada vendor é
 * ledger interno (bavix/laravel-wallet); "pagar" aqui envia o email/notificação
 * "pagamento enviado" e zera o saldo -- o admin faz a transferência bancária
 * manualmente (por isso a lista mostra o IBAN) e só depois clica em pagar. É
 * exatamente o que o Filament já faz, replicado tal e qual (mesmas classes de
 * Mail/Notification, mesmo meta da transação).
 */
class VendorPaymentController extends Controller
{
    /** O código vem do modelo (`Vendor::payoutBlocker()`); a frase é deste ecrã. */
    private const RAZAO_DA_RETENCAO = [
        'iban_missing' => 'Pagamento retido: este técnico não tem IBAN. Não há para onde transferir.',
        'fiscal_address_missing' => 'Pagamento retido: falta a morada fiscal do técnico, '
            .'sem a qual não se emite fatura. O saldo fica na carteira dele.',
        'at_user_missing' => 'Pagamento retido: o técnico ainda não deu o acesso de subutilizador da AT. '
            .'O saldo fica na carteira dele até isso ser preenchido.',
    ];

    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $vendors = Vendor::whereHas('user.wallet', fn ($q) => $q->where('balance', '>', 0))
            // `payoutBlocker()` le colunas do proprio vendor e, no pior caso, faz
            // duas queries por tecnico (moradas + contagem de servicos). So chega la
            // quem tem IBAN e morada; os outros saem antes.
            ->with(['user.wallet'])
            ->paginate($perPage);

        /*
         * Total faturado através da Piquet e comissão, por técnico.
         *
         * A carteira só guarda a PARTE DO TÉCNICO, por isso estes valores não se
         * conseguem derivar do saldo -- e a comissão não é uma percentagem fixa
         * (é `amount - amount_for_vendor`, definido serviço a serviço). Vêm daqui
         * ou não vêm de lado nenhum.
         *
         * Uma query agregada para os vendors da página (sem N+1). As colunas são
         * INTEGER em cêntimos; devolve-se em euros para casar com `balance`, que
         * já vem em euros do balance_float.
         */
        $totais = Service::query()
            ->whereIn('vendor_id', collect($vendors->items())->pluck('id'))
            ->where('status', ServiceStatus::CLOSED)
            ->where('is_test', false)
            ->selectRaw('vendor_id, SUM(amount) as faturado, SUM(amount - amount_for_vendor) as comissao')
            ->groupBy('vendor_id')
            ->get()
            ->keyBy('vendor_id');

        return ApiSuccessResponse::make([
            'items' => collect($vendors->items())
                ->map(fn (Vendor $v) => $this->present($v, $totais->get($v->id)))
                ->all(),
            'meta' => [
                'current_page' => $vendors->currentPage(),
                'last_page' => $vendors->lastPage(),
                'per_page' => $vendors->perPage(),
                'total' => $vendors->total(),
            ],
        ]);
    }

    /**
     * `amount` (euros, opcional): paga SÓ esse valor, e não o saldo inteiro.
     *
     * É o que o backoffice usa nos lotes de pagamento: o lote é aprovado com o
     * saldo de um dia, a transferência sai com esse valor, e o técnico pode ter
     * ganho mais entretanto. Zerar o saldo inteiro debitava-lhe da carteira
     * dinheiro que nunca lhe foi transferido. Sem `amount`, paga tudo, como o
     * Filament sempre fez.
     *
     * `reference` (opcional) fica no histórico da carteira, para se saber de
     * que lote veio o débito.
     */
    public function pay(Request $request, Vendor $vendor): ApiSuccessResponse|ApiErrorResponse
    {
        $wallet = $vendor->user->wallet;
        $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        if ($wallet->balance <= 0) {
            return new ApiErrorResponse(null, 'Este vendor não tem saldo por pagar.', 409);
        }

        /*
         * Não se transfere dinheiro por trabalho que não se consegue faturar.
         *
         * O dinheiro está na carteira dele de propósito -- o trabalho foi feito e o
         * cliente foi cobrado -- mas fica retido até o impedimento sair. A app
         * avisa-o disso; este guarda é o que torna o aviso verdadeiro. Sem ele, o
         * aviso é uma promessa que um clique distraído no backoffice desmente.
         *
         * 409 e não 422: o pedido está bem formado, o estado do técnico é que não
         * permite. A mensagem é para o admin, na terceira pessoa -- a app fala com
         * o técnico e tem as suas próprias palavras para a mesma regra.
         */
        if ($blocker = $vendor->payoutBlocker()) {
            return new ApiErrorResponse(null, self::RAZAO_DA_RETENCAO[$blocker], 409);
        }

        $centimos = $request->filled('amount')
            ? (int) round(((float) $request->input('amount')) * 100)
            : (int) $wallet->balance;

        if ($centimos > (int) $wallet->balance) {
            return new ApiErrorResponse(null, sprintf(
                'O valor a pagar (%.2f €) é maior do que o saldo do técnico (%.2f €).',
                $centimos / 100,
                (float) $wallet->balance_float,
            ), 409);
        }

        $amount = $centimos / 100;

        // Mesma sequência do Filament (App\Filament\Pages\VendorPayments::table()):
        // email, notificação (push + BD) e só depois o débito do saldo.
        Mail::to($vendor->user->email)->send(new PaymentSentMail($vendor, $amount));
        $vendor->user->notify(new PaymentSentNotification($amount));
        $wallet->withdraw($centimos, array_filter([
            'type' => 'Debit',
            'description' => 'Transfer to account',
            'admin_description' => 'Transfer to account',
            'class' => Vendor::class,
            'id' => $vendor->getKey(),
            'admin_id' => auth()->id(),
            'reference' => $request->input('reference'),
        ], fn ($v) => $v !== null));

        return ApiSuccessResponse::make([
            'vendor_id' => $vendor->id,
            'amount_paid' => (float) $amount,
            'balance_left' => (float) $wallet->refresh()->balance_float,
        ]);
    }

    /**
     * @param  object|null  $totais  Linha agregada (faturado, comissao) em cêntimos,
     *                               ou null se o técnico ainda não fechou serviços.
     */
    private function present(Vendor $vendor, ?object $totais = null): array
    {
        // NÃO usar vendor->name / user->name -- ver nota em SystemProfitController
        // e VendorDocumentController sobre User::setNameAttribute() nunca gravar
        // a coluna 'name'. first_name/last_name são as colunas reais.
        $user = $vendor->user;

        return [
            'id' => $vendor->id,
            'vendor_name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null,
            // Espaçado a cada 4 caracteres, tal como o Filament (formatStateUsing).
            'iban' => $vendor->iban ? trim(preg_replace('/(\w{4})(?=\w)/', '$1 ', $vendor->iban)) : null,
            // balance_float vem como string (contrato do bavix/laravel-wallet) --
            // sem o cast, o JSON manda "150.00" como string em vez de número.
            'balance' => (float) $user->wallet->balance_float,
            // Totais COM IVA (como todo o dinheiro no sistema); o líquido é
            // /(1+IVA), tal como os acessores *_without_vat do model Service.
            // null (e não 0) quando o técnico ainda não fechou nenhum serviço --
            // zero seria indistinguível de "faturou 0 €".
            'total_invoiced' => $totais ? round(((int) $totais->faturado) / 100, 2) : null,
            'commission' => $totais ? round(((int) $totais->comissao) / 100, 2) : null,
            // Porque e que este saldo nao se pode pagar. O dashboard tem de poder
            // marcar a linha em vez de o admin descobrir pelo 409 depois de clicar --
            // e o CODIGO diz-lhe qual dos tres impedimentos e, nao so que ha um.
            'payout_blocked' => $vendor->payout_blocked,
            'payout_blocker' => $vendor->payoutBlocker(),
        ];
    }
}
