<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\NotificationCampaign;
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
 * Só leitura, mais um interruptor para parar uma campanha. Criar campanhas
 * continua no Filament de propósito: criar uma é enviar push a milhares de
 * pessoas, e isso não devia ser um botão a mais num ecrã de consulta.
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
    public function setActive(Request $request, NotificationCampaign $campaign): ApiSuccessResponse
    {
        $campaign->update(['is_active' => $request->boolean('active')]);

        return ApiSuccessResponse::make($this->present($campaign->fresh()->loadCount([
            'logs as enviados',
            'logs as entregues' => fn ($q) => $q->where('success', true),
            'logs as falhados' => fn ($q) => $q->where('success', false),
            'logs as abertos' => fn ($q) => $q->whereNotNull('opened_at'),
            'logs as cliques' => fn ($q) => $q->whereNotNull('deep_link_clicked_at'),
            'optOuts as saidas',
        ])));
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
