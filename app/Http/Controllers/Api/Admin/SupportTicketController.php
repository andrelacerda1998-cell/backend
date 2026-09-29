<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\ReplySupportTicketRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\SupportTicket;
use Illuminate\Http\Request;

/**
 * Tickets de suporte dos TECNICOS, para o backoffice.
 *
 * Existiam desde sempre e so se viam no Filament. O backoffice tem uma caixa
 * de entrada de suporte -- mas lia outra tabela, no Supabase, onde caem os
 * tickets da app do CLIENTE. Eram dois sistemas paralelos, e o de quem
 * trabalha para a Piquet era o invisivel.
 *
 * Custou: as duas unicas mensagens que tecnicos alguma vez mandaram ficaram
 * dias sem resposta (a da Danubia, seis). E a contestacao de uma falta abre um
 * ticket destes -- alguem a quem foi cobrada metade do que ia receber
 * reclamava para um sitio que ninguem via.
 */
class SupportTicketController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = SupportTicket::query()
            ->with('vendor.user')
            // Abertos primeiro -- sao os que pedem alguma coisa a alguem --
            // e dentro de cada grupo os mais antigos no topo, que sao os que
            // estao a espera ha mais tempo.
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderBy('created_at');

        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        $tickets = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($tickets->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function show(SupportTicket $supportTicket): ApiSuccessResponse
    {
        $supportTicket->load('vendor.user');

        return ApiSuccessResponse::make($this->present($supportTicket));
    }

    /**
     * Responder. O tecnico le a resposta na app, ao abrir o ticket.
     *
     * NAO ha notificacao: o Filament tambem nao a envia, e acrescenta-la aqui
     * faria com que responder pelo backoffice avisasse e responder pelo
     * Filament nao -- a mesma accao com dois comportamentos, conforme o sitio.
     * Fica por fazer nos dois, de proposito.
     */
    public function update(ReplySupportTicketRequest $request, SupportTicket $supportTicket): ApiSuccessResponse
    {
        $dados = $request->validated();

        if (array_key_exists('admin_reply', $dados) && $dados['admin_reply'] !== null) {
            $dados['replied_at'] = now();
            // Responder marca como respondido, a nao ser que se diga outra
            // coisa. Sem isto, um ticket respondido continuava a contar como
            // aberto na lista de quem espera.
            $dados['status'] ??= 'answered';
        }

        $supportTicket->update($dados);
        $supportTicket->load('vendor.user');

        return ApiSuccessResponse::make($this->present($supportTicket));
    }

    private function present(SupportTicket $t): array
    {
        return [
            'id' => $t->id,
            'subject' => $t->subject,
            'message' => $t->message,
            'status' => $t->status,
            'admin_reply' => $t->admin_reply,
            'replied_at' => $t->replied_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            /*
             * Quem escreveu. Sem isto o backoffice tinha um assunto e uma
             * mensagem sem dono -- e a primeira coisa que se quer fazer a um
             * tecnico parado e ligar-lhe.
             */
            'vendor' => [
                'id' => $t->vendor_id,
                'name' => $t->vendor?->user?->name,
                'phone_number' => $t->vendor?->user?->phone_number,
            ],
            /*
             * Contestacao de falta: nasce de NoShowController::dispute, que
             * poe o id do servico no assunto. Marca-se aqui para o backoffice
             * nao ter de reconhecer a frase -- se ela mudar, muda num sitio.
             */
            'is_no_show_dispute' => (bool) preg_match('/^Contesta[çc][ãa]o de falta — servi[çc]o #(\d+)$/u', (string) $t->subject),
            'disputed_service_id' => preg_match('/#(\d+)$/', (string) $t->subject, $m) ? (int) $m[1] : null,
        ];
    }
}
