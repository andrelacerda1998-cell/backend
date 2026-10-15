<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Message;
use App\Models\Service;
use App\Services\Auditoria\StaffDoBackoffice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * A conversa entre o cliente e o técnico de um serviço, para resolver uma
 * reclamação ou uma disputa (decisão do André, 15/10/2026).
 *
 * As mensagens chegam cifradas com a chave pública do serviço e ficam assim na
 * base de dados, mas a chave privada é do servidor (`services.rsa`): é ele que
 * as decifra para as entregar às apps (ChatController). Não é cifra ponta a
 * ponta. O backoffice não as lia porque não havia esta rota, e não por não
 * poder.
 *
 * Só se lê com um CASO, e o caso tem de existir:
 *  - `problema`: alguém reportou um problema na app (ReportarProblema);
 *  - `falta`: o serviço foi dado como falta do técnico;
 *  - `ticket:TK-…`: um ticket de Suporte ligado ao pedido. Os tickets vivem no
 *    Supabase do backoffice, que é quem confirma que existe e está aberto.
 * E com o MOTIVO e QUEM (X-Staff-Email, ver StaffDoBackoffice). O backoffice
 * guarda a leitura em `acoes_da_equipa`; aqui fica também no log.
 *
 * Ler não marca nada como lido: o "lido" é do cliente e do técnico.
 */
class ConversaDoServicoController extends Controller
{
    /** Os casos que este serviço tem do lado da app, sem o conteúdo da conversa. */
    public function casos(Service $service): ApiSuccessResponse
    {
        return ApiSuccessResponse::make([
            'problema' => $service->problem_reported_at ? [
                'em' => $service->problem_reported_at->toIso8601String(),
                'por' => $service->problem_reported_by,
                'motivo' => $service->problem_reason,
            ] : null,
            'falta' => $service->vendor_no_show_at ? [
                'em' => $service->vendor_no_show_at->toIso8601String(),
            ] : null,
            'mensagens' => $service->messages()->count(),
        ]);
    }

    public function ler(Request $request, Service $service): ApiSuccessResponse|ApiErrorResponse
    {
        $dados = $request->validate([
            'caso' => ['required', 'string', 'regex:/^(problema|falta|ticket:TK-\d{1,10})$/'],
            'motivo' => ['required', 'string', 'min:15', 'max:500'],
        ]);

        $staff = StaffDoBackoffice::emailAtual();

        if ($staff === null) {
            return new ApiErrorResponse(null, 'Falta saber quem do staff está a ler (X-Staff-Email).', 422);
        }

        $semCaso = match ($dados['caso']) {
            'problema' => $service->problem_reported_at === null,
            'falta' => $service->vendor_no_show_at === null,
            default => false,
        };

        if ($semCaso) {
            return new ApiErrorResponse(null, 'Este pedido não tem esse caso: a conversa só se lê para resolver uma reclamação ou disputa.', 403);
        }

        Log::info('[conversa] lida pelo staff', [
            'service_id' => $service->id,
            'staff' => $staff,
            'caso' => $dados['caso'],
            'motivo' => $dados['motivo'],
        ]);

        $tecnico = $service->vendor?->user_id;

        $mensagens = $service->messages()->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'de' => match (true) {
                    $m->user_id === $service->customer_id => 'cliente',
                    $tecnico !== null && $m->user_id === $tecnico => 'tecnico',
                    default => 'outro',
                },
                'texto' => $service->rsa ? $this->decifrar((string) $m->message, $service->rsa) : null,
                'enviada_em' => $m->created_at?->toIso8601String(),
                'lida' => (bool) $m->is_read,
            ])
            ->values()
            ->all();

        return ApiSuccessResponse::make(['mensagens' => $mensagens]);
    }

    /**
     * A mesma decifra do ChatController. Uma mensagem que não se decifra não
     * derruba as outras: vem sem texto, e o backoffice diz que não se leu.
     *
     * Não chega olhar para o `false`: o OpenSSL 3 não falha a decifrar lixo
     * com PKCS#1 v1.5 ("implicit rejection", contra o ataque Marvin) e devolve
     * bytes ao calhas. O que não é texto (UTF-8 inválido ou caracteres de
     * controlo além de mudanças de linha e tabs) conta como não decifrado.
     */
    private function decifrar(string $cifrada, string $chavePrivada): ?string
    {
        if (! openssl_private_decrypt((string) base64_decode($cifrada, true), $texto, $chavePrivada)) {
            return null;
        }

        return mb_check_encoding($texto, 'UTF-8') && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $texto)
            ? $texto
            : null;
    }
}
