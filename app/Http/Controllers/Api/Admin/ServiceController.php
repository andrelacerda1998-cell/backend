<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\CandidateStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use Illuminate\Http\Request;

/**
 * Serviços pedidos na app, para o backoffice.
 *
 * É a ponte que faltava: os pedidos entram pela app, ficam aqui, e o backoffice
 * não os via -- só via as leads do formulário do site. Com o formulário a sair
 * da landing, sem este endpoint o backoffice deixava de saber que alguém pediu
 * alguma coisa.
 *
 * Devolve também o estado do MATCHING (App\Models\ServiceCandidate): quantos
 * técnicos foram notificados, quantos aceitaram, quantos recusaram e quantos
 * deixaram expirar. É a informação que diz se a rede chega para a procura, e
 * hoje não sai daqui para lado nenhum.
 *
 * Valores: as colunas são INTEGER em cêntimos; saem em euros, para casar com o
 * resto da API de admin (ver VendorPaymentController).
 */
class ServiceController extends Controller
{
    /** Serviços de teste nunca contam -- é a mesma regra do resto do admin. */
    private const EXCLUI_TESTES = true;

    /** Quem disse que sim, independentemente do que aconteceu depois. */
    private const ACEITARAM = [CandidateStatus::ACCEPTED, CandidateStatus::SELECTED, CandidateStatus::LOST];

    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = Service::query()
            ->with(['customerUser', 'vendor.user', 'serviceType', 'schedule', 'media', 'operationAreas'])
            ->withCount([
                'candidates as candidates_invited',
                'candidates as candidates_notified' => fn ($q) => $q->where('status', CandidateStatus::NOTIFIED),
                // ACEITOU ALGUMA VEZ. Quem aceita passa depois a SELECTED (foi
                // escolhido) ou a LOST (outro foi escolhido, ou o pedido fechou).
                // Contar só ACCEPTED dava zero em todo o pedido que avançou — o
                // contrário do que a coluna diz.
                'candidates as candidates_accepted' => fn ($q) => $q->whereIn('status', self::ACEITARAM),
                'candidates as candidates_declined' => fn ($q) => $q->where('status', CandidateStatus::DECLINED),
                'candidates as candidates_expired' => fn ($q) => $q->where('status', CandidateStatus::EXPIRED),
            ])
            ->latest('id');

        if (self::EXCLUI_TESTES) {
            $query->where('is_test', false);
        }

        /*
         * Vários estados de uma vez. Os separadores do backoffice são GRUPOS
         * ("Em curso" = técnico em casa + à espera de confirmação), e o filtro
         * de um só estado não os conseguia exprimir: o backoffice deixava de o
         * mandar e cada separador mostrava a lista inteira.
         */
        $estados = array_values(array_filter(array_map(
            'trim',
            explode(',', $request->string('statuses')->toString()),
        )));

        if ($estados) {
            $query->whereIn('status', $estados);
        } elseif ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        // A cidade vive na morada guardada no serviço (JSON), com dois nomes
        // possíveis consoante a origem — os mesmos dois que o present() lê.
        if ($cidade = trim($request->string('city')->toString())) {
            $query->where(fn ($q) => $q
                ->where('address->city', $cidade)
                ->orWhere('address->locality', $cidade));
        }

        // `category_id` no backoffice é o tipo de serviço (ver present()).
        if ($request->filled('category_id')) {
            $query->where('services_type_id', $request->integer('category_id'));
        }

        /*
         * Procura por nome ou telefone do cliente. É por aqui que se resolve um
         * caso ao telefone, por isso o telefone conta tanto como o nome.
         */
        if ($termo = trim($request->string('search')->toString())) {
            // "#282" ou "282" é o número do serviço — o que o cliente lê no
            // recibo e diz ao telefone. Antes só se procurava pelo cliente, e
            // o número não encontrava nada.
            $numero = ltrim($termo, '#');

            $query->where(function ($q) use ($termo, $numero) {
                if (ctype_digit($numero)) {
                    // Qualificado com a tabela: os whereHas abaixo juntam
                    // outras tabelas com a sua própria coluna `id`.
                    $q->orWhere('services.id', (int) $numero);
                }

                $q->orWhereHas('customerUser', function ($c) use ($termo) {
                    $c->where('name', 'like', "%{$termo}%")
                        ->orWhere('phone_number', 'like', "%{$termo}%")
                        ->orWhere('email', 'like', "%{$termo}%");
                })
                    // E pelo técnico: "o serviço do Rui de ontem".
                    ->orWhereHas('vendor.user', function ($v) use ($termo) {
                        $v->where('name', 'like', "%{$termo}%")
                            ->orWhere('phone_number', 'like', "%{$termo}%");
                    });
            });
        }

        if ($desde = $request->string('from')->toString()) {
            $query->whereDate('created_at', '>=', $desde);
        }
        if ($ate = $request->string('to')->toString()) {
            $query->whereDate('created_at', '<=', $ate);
        }

        $servicos = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($servicos->items())
                ->map(fn (Service $s) => $this->present($s))
                ->all(),
            'meta' => [
                'current_page' => $servicos->currentPage(),
                'last_page' => $servicos->lastPage(),
                'per_page' => $servicos->perPage(),
                'total' => $servicos->total(),
            ],
        ]);
    }

    /**
     * Um serviço com a lista de candidatos, para o detalhe do pedido.
     *
     * Separado do index de propósito: a lista de candidatos por serviço numa
     * listagem de 100 seriam centenas de linhas que ninguém lê.
     */
    public function show(Service $service): ApiSuccessResponse
    {
        $service->load(['customerUser', 'vendor.user', 'serviceType', 'schedule', 'candidates.vendor.user', 'media', 'operationAreas']);

        return ApiSuccessResponse::make([
            ...$this->present($service),
            /*
             * As fotografias que o cliente anexou, com URL assinado.
             *
             * `customerPhotosPayload()` é o mesmo método que as apps do cliente
             * e do técnico usam -- uma só definição de validade (60 min) em vez
             * de três que divergiriam à primeira alteração. Só no detalhe: na
             * listagem vai apenas a contagem.
             */
            'customer_photos' => $service->customerPhotosPayload(),
            /*
             * As contagens, com nome próprio. No detalhe, `candidates` é a
             * LISTA (abaixo) e substitui as contagens que o present() pôs com o
             * mesmo nome — o detalhe nunca as tinha.
             */
            'candidate_counts' => $this->contagemDeCandidatos($service),
            'candidates' => $service->candidates
                ->sortBy('rank')
                ->map(fn ($c) => [
                    'vendor_id' => $c->vendor_id,
                    'vendor_name' => $c->vendor?->user?->name,
                    'status' => $c->status instanceof CandidateStatus ? $c->status->value : $c->status,
                    'rank' => $c->rank,
                    'wave' => $c->wave,
                    'rating_average' => $c->rating_average,
                    'quoted_amount' => $this->euros($c->quoted_amount),
                    'quoted_distance' => $c->quoted_distance,
                    'notified_at' => optional($c->created_at)->toIso8601String(),
                    // A hora em que RESPONDEU, e null se ainda não respondeu.
                    // Era o `updated_at`, que muda com qualquer alteração à
                    // linha — e dava hora de resposta a quem nunca respondeu.
                    'responded_at' => optional($c->responded_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Quantos técnicos foram convidados e o que responderam.
     *
     * Na listagem vem do `withCount` (uma query para a página toda). No
     * detalhe os candidatos já estão carregados, e conta-se a partir deles:
     * antes o detalhe dizia sempre zero, porque só a listagem pedia as
     * contagens. `notified` são os que ainda podem responder.
     *
     * @return array<string, int>
     */
    private function contagemDeCandidatos(Service $service): array
    {
        if ($service->relationLoaded('candidates')) {
            $estados = $service->candidates->map(
                fn ($c) => $c->status instanceof CandidateStatus ? $c->status : CandidateStatus::tryFrom((string) $c->status)
            );

            return [
                'invited' => $estados->count(),
                'notified' => $estados->filter(fn ($e) => $e === CandidateStatus::NOTIFIED)->count(),
                'accepted' => $estados->filter(fn ($e) => in_array($e, self::ACEITARAM, true))->count(),
                'declined' => $estados->filter(fn ($e) => $e === CandidateStatus::DECLINED)->count(),
                'expired' => $estados->filter(fn ($e) => $e === CandidateStatus::EXPIRED)->count(),
            ];
        }

        return [
            'invited' => (int) ($service->candidates_invited ?? 0),
            'notified' => (int) ($service->candidates_notified ?? 0),
            'accepted' => (int) ($service->candidates_accepted ?? 0),
            'declined' => (int) ($service->candidates_declined ?? 0),
            'expired' => (int) ($service->candidates_expired ?? 0),
        ];
    }

    /** Cêntimos → euros. `null` fica `null`: zero seria dizer que é de graça. */
    private function euros(int|float|null $centimos): ?float
    {
        return $centimos === null ? null : round($centimos / 100, 2);
    }

    /**
     * Nomes em snake_case e iguais aos que o backoffice já espera
     * (`LaravelServiceRow`), para a ligação ser só ligar o interruptor.
     */
    private function present(Service $service): array
    {
        $morada = is_array($service->address) ? $service->address : [];
        $comissao = $service->amount !== null && $service->amount_for_vendor !== null
            ? $service->amount - $service->amount_for_vendor
            : null;

        return [
            'id' => (string) $service->id,
            'customer_id' => $service->customer_id,
            'customer_name' => $service->customerUser?->name,
            'customer_phone' => $service->customerUser?->phone_number,
            'technician_id' => $service->vendor_id,
            'technician_name' => $service->vendor?->user?->name,
            'category_id' => $service->services_type_id,
            'category_name' => $service->serviceType?->name,
            'service_name' => $service->serviceType?->name,
            'location' => $morada['address'] ?? $morada['street'] ?? null,
            'city' => $morada['city'] ?? $morada['locality'] ?? null,
            'source' => 'app',
            'status' => $service->status?->value ?? (string) $service->status,
            'payment_status' => $service->payment_status?->value ?? (string) $service->payment_status,
            'requested_at' => optional($service->created_at)->toIso8601String(),
            /*
             * A marcação vive na tabela `schedules`, não no serviço. O dia e a
             * hora saem separados como estão gravados -- juntá-los aqui seria
             * inventar um fuso que ninguém escreveu.
             */
            'scheduled_day' => $service->schedule?->scheduled_day,
            'scheduled_time' => $service->schedule?->scheduled_time_start,
            'started_at' => optional($service->arrived_at)->toIso8601String(),
            'on_the_way_at' => optional($service->on_the_way_at)->toIso8601String(),
            /*
             * Não há coluna de conclusão: o serviço fecha por mudança de estado
             * (Finished/Closed). `updated_at` é o mais próximo que existe e só
             * se devolve quando o serviço está mesmo fechado -- em qualquer
             * outro estado seria a data da última alteração, que não é a mesma
             * coisa e induziria em erro.
             */
            'completed_at' => in_array($service->status?->value, ['Finished', 'Closed'], true)
                ? optional($service->updated_at)->toIso8601String()
                : null,
            'total_customer_value' => $this->euros($service->amount),
            'technician_value' => $this->euros($service->amount_for_vendor),
            'piquet_revenue' => $this->euros($comissao),
            'rating' => $service->rating_by_customer,
            'customer_notes' => $service->customer_notes,
            /*
             * Pedido personalizado: o que o cliente descreveu por palavras dele,
             * quando não há tipo de catálogo que sirva.
             *
             * O backoffice mostrava aqui seis pedidos inventados enquanto os
             * reais -- serviços com `is_custom` -- não chegavam à API de admin.
             */
            'is_custom' => (bool) $service->is_custom,
            'custom_description' => $service->custom_description,
            'custom_duration_minutes' => $service->custom_duration_minutes,
            'custom_dispatched_at' => $service->custom_dispatched_at?->toIso8601String(),
            'custom_categories' => $service->operationAreas->pluck('name')->all(),
            /*
             * Quantas fotografias o cliente anexou.
             *
             * Só a CONTAGEM na listagem: os URLs são assinados e temporários, e
             * gerar dezenas por página para imagens que ninguém abriu seria
             * trabalho deitado fora. O detalhe (`show`) traz os URLs.
             */
            'customer_photos_count' => $service->getMedia('customer')->count(),
            /*
             * O estado do matching. Sem isto não se sabe a diferença entre "não
             * apareceu ninguém" e "ninguém foi sequer perguntado" -- que é a
             * diferença entre um problema de rede e um problema de sistema.
             */
            'candidates' => $this->contagemDeCandidatos($service),
        ];
    }
}
