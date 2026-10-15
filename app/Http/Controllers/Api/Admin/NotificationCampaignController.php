<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CampanhaDePushRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\NotificationCampaign;
use App\Services\Campanhas\PublicoDaCampanha;
use App\Services\Translation\Translator;
use Illuminate\Http\Request;

/**
 * Campanhas de notificação (push) para o backoffice.
 *
 * Existem e funcionam -- saem pelo canal Expo e ficam registadas em
 * `notification_campaign_logs`, com entrega, abertura e clique no deep link.
 * O que não existia era forma de as ver fora do Filament: o backoffice tinha um
 * ecrã de "Push" que guardava campanhas no browser e inventava as métricas de
 * entrega com `rand()`. Este endpoint substitui essa ficção pelos números reais.
 *
 * Criar passou também para aqui (15/10/2026, pedido do André), mas não como um
 * botão a mais: uma campanha nasce DESLIGADA, como rascunho; o backoffice
 * mostra a quantas pessoas vai chegar (`publico`, a mesma regra do envio) e só
 * depois alguém a liga, com motivo. Um rascunho edita-se e apaga-se enquanto
 * nunca tiver sido enviado; depois disso fica como está, porque os números já
 * são dele.
 */
class NotificationCampaignController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = NotificationCampaign::query()
            ->withCount([
                'logs as enviados',
                'logs as entregues' => fn ($q) => $q->where('success', true),
                'logs as falhados' => fn ($q) => $q->where('success', false),
                'logs as abertos' => fn ($q) => $q->whereNotNull('opened_at'),
                'logs as cliques' => fn ($q) => $q->whereNotNull('deep_link_clicked_at'),
                'optOuts as saidas',
            ])
            ->latest('id');

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($alvo = $request->string('target')->toString()) {
            $query->where('target_type', $alvo);
        }

        $campanhas = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($campanhas->items())
                ->map(fn (NotificationCampaign $c) => $this->present($c))
                ->all(),
            'meta' => [
                'current_page' => $campanhas->currentPage(),
                'last_page' => $campanhas->lastPage(),
                'per_page' => $campanhas->perPage(),
                'total' => $campanhas->total(),
            ],
        ]);
    }

    /**
     * Ligar ou desligar uma campanha.
     *
     * É a única escrita, e é a que interessa a quem está a olhar para os
     * números: ver uma campanha a falhar e não a conseguir parar sem abrir
     * outro sistema é o pior dos dois mundos.
     */
    public function setActive(Request $request, NotificationCampaign $campaign): ApiSuccessResponse|ApiErrorResponse
    {
        $ligar = $request->boolean('active');

        // Ligada com o inglês por rever, a campanha não saía para ninguém
        // (NotificationCampaign::shouldSend) e parecia ativa. Diz-se já.
        if ($ligar && $campaign->englishIsDraft()) {
            return new ApiErrorResponse(null, 'Revê a tradução inglesa antes de a ligar: até lá a campanha não sai para ninguém.', 409);
        }

        $campaign->update(['is_active' => $ligar]);

        return ApiSuccessResponse::make($this->comNumeros($campaign->fresh()));
    }

    /** Cria a campanha como rascunho: desligada, à espera de quem a ligue. */
    public function store(CampanhaDePushRequest $request): ApiSuccessResponse
    {
        $campanha = NotificationCampaign::create($request->atributos());

        return ApiSuccessResponse::make($this->comNumeros($campanha->fresh()), statusCode: 201);
    }

    /** Corrige um rascunho. Depois do primeiro envio já não: os números são daquela campanha. */
    public function update(CampanhaDePushRequest $request, NotificationCampaign $campaign): ApiSuccessResponse|ApiErrorResponse
    {
        if ($erro = $this->naoERascunho($campaign)) {
            return $erro;
        }

        $campaign->update($request->atributos());

        return ApiSuccessResponse::make($this->comNumeros($campaign->fresh()));
    }

    public function destroy(NotificationCampaign $campaign): ApiSuccessResponse|ApiErrorResponse
    {
        if ($erro = $this->naoERascunho($campaign)) {
            return $erro;
        }

        $campaign->delete();

        return ApiSuccessResponse::make();
    }

    /** A quantas pessoas chega uma campanha ainda por gravar (o formulário a ser preenchido). */
    public function publico(CampanhaDePushRequest $request, PublicoDaCampanha $publico): ApiSuccessResponse
    {
        return ApiSuccessResponse::make(['pessoas' => $publico->contar(new NotificationCampaign($request->atributos()))]);
    }

    /** A quantas pessoas chega uma campanha gravada: o número que se confirma antes de a ligar. */
    public function publicoDe(NotificationCampaign $campaign, PublicoDaCampanha $publico): ApiSuccessResponse
    {
        return ApiSuccessResponse::make(['pessoas' => $publico->contar($campaign)]);
    }

    /**
     * Um rascunho do inglês a partir do português, como o botão do Filament.
     * Rascunho: a campanha só sai quando alguém o marca como revisto.
     */
    public function traduzir(Request $request, Translator $tradutor): ApiSuccessResponse|ApiErrorResponse
    {
        $dados = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $tradutor->isConfigured()) {
            return new ApiErrorResponse(null, 'A tradução automática está desligada. Escreve o inglês à mão, ou deixa vazio: nesse caso sai o português.', 503);
        }

        $traduzido = [];

        foreach (['title', 'body'] as $campo) {
            $texto = trim((string) ($dados[$campo] ?? ''));
            $traduzido[$campo] = $texto === '' ? '' : $tradutor->translate($texto, 'pt-pt', 'en');
        }

        if (in_array(null, $traduzido, true)) {
            return new ApiErrorResponse(null, 'O serviço de tradução não respondeu. Escreve o inglês à mão, ou deixa vazio.', 502);
        }

        return ApiSuccessResponse::make($traduzido);
    }

    private function naoERascunho(NotificationCampaign $campaign): ?ApiErrorResponse
    {
        if ($campaign->is_active || $campaign->last_sent_at !== null) {
            return new ApiErrorResponse(null, 'Só se mexe numa campanha desligada e que nunca foi enviada.', 409);
        }

        return null;
    }

    private function comNumeros(NotificationCampaign $campaign): array
    {
        return $this->present($campaign->loadCount([
            'logs as enviados',
            'logs as entregues' => fn ($q) => $q->where('success', true),
            'logs as falhados' => fn ($q) => $q->where('success', false),
            'logs as abertos' => fn ($q) => $q->whereNotNull('opened_at'),
            'logs as cliques' => fn ($q) => $q->whereNotNull('deep_link_clicked_at'),
            'optOuts as saidas',
        ]));
    }

    /**
     * O texto na língua que se escreve no backoffice.
     *
     * `title` e `body` são traduções, e o Filament grava '' nas abas que não
     * foram preenchidas -- por isso não basta `??`, é preciso o primeiro valor
     * COM conteúdo. É a mesma escolha que a CampaignNotification faz na hora de
     * enviar; se divergisse, o backoffice mostrava um texto e o telemóvel
     * recebia outro.
     */
    private function texto(mixed $campo, string $default = ''): string
    {
        if (! is_array($campo)) {
            return (string) ($campo ?: $default);
        }

        foreach (['pt-pt', 'pt', 'en'] as $lingua) {
            if (filled($campo[$lingua] ?? null)) {
                return (string) $campo[$lingua];
            }
        }

        return (string) (collect($campo)->first(fn ($v) => filled($v)) ?? $default);
    }

    private function present(NotificationCampaign $c): array
    {
        $enviados = (int) ($c->enviados ?? 0);
        $entregues = (int) ($c->entregues ?? 0);
        $abertos = (int) ($c->abertos ?? 0);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'title' => $this->texto($c->title, $c->name),
            'body' => $this->texto($c->body),
            // 'vendor' | 'customer' | 'both' — quem recebe.
            'target_type' => $c->target_type,
            'user_status' => $c->user_status,
            'frequency_type' => $c->frequency_type,
            'frequency_value' => $c->frequency_value,
            'frequency_unit' => $c->frequency_unit,
            'is_active' => (bool) $c->is_active,
            // Desligada e nunca enviada: edita-se e apaga-se no backoffice.
            'rascunho' => ! $c->is_active && $c->last_sent_at === null,
            // Há inglês por rever: enquanto houver, a campanha não sai.
            'ingles_por_rever' => $c->englishIsDraft(),
            // O que o formulário do backoffice precisa para editar um rascunho.
            'textos' => [
                'title' => ['pt-pt' => (string) data_get($c->title, 'pt-pt', ''), 'en' => (string) data_get($c->title, 'en', '')],
                'body' => ['pt-pt' => (string) data_get($c->body, 'pt-pt', ''), 'en' => (string) data_get($c->body, 'en', '')],
            ],
            'open_type' => $c->open_type,
            'open_id' => $c->open_id !== null ? (int) $c->open_id : null,
            'vendor_eligibility' => $c->vendor_eligibility,
            'vendor_missing_schedule_address' => (bool) $c->vendor_missing_schedule_address,
            'customer_never_requested' => (bool) $c->customer_never_requested,
            'inactive_days' => $c->inactive_days,
            'starts_at' => optional($c->starts_at)->toIso8601String(),
            'ends_at' => optional($c->ends_at)->toIso8601String(),
            'last_sent_at' => optional($c->last_sent_at)->toIso8601String(),
            'next_send_at' => optional($c->next_send_at)->toIso8601String(),
            'stats' => [
                'enviados' => $enviados,
                'entregues' => $entregues,
                'falhados' => (int) ($c->falhados ?? 0),
                'abertos' => $abertos,
                'cliques' => (int) ($c->cliques ?? 0),
                'saidas' => (int) ($c->saidas ?? 0),
                /*
                 * Percentagens calculadas aqui e `null` quando não há base:
                 * uma taxa de 0% sobre zero envios diria que a campanha correu
                 * mal, quando o que aconteceu foi não ter corrido.
                 */
                'taxa_entrega' => $enviados > 0 ? round($entregues / $enviados * 100, 1) : null,
                'taxa_abertura' => $entregues > 0 ? round($abertos / $entregues * 100, 1) : null,
            ],
        ];
    }
}
