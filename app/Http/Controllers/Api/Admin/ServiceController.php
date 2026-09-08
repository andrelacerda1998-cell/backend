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

    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = Service::query()
            ->with(['customerUser', 'vendor.user', 'serviceType', 'schedule'])
            ->withCount([
                'candidates as candidates_notified' => fn ($q) => $q->where('status', CandidateStatus::NOTIFIED),
                'candidates as candidates_accepted' => fn ($q) => $q->where('status', CandidateStatus::ACCEPTED),
                'candidates as candidates_declined' => fn ($q) => $q->where('status', CandidateStatus::DECLINED),
                'candidates as candidates_expired' => fn ($q) => $q->where('status', CandidateStatus::EXPIRED),
            ])
            ->latest('id');

        if (self::EXCLUI_TESTES) {
            $query->where('is_test', false);
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        /*
         * Procura por nome ou telefone do cliente. É por aqui que se resolve um
         * caso ao telefone, por isso o telefone conta tanto como o nome.
         */
        if ($termo = trim($request->string('search')->toString())) {
            $query->whereHas('customerUser', function ($q) use ($termo) {
                $q->where('name', 'like', "%{$termo}%")
                    ->orWhere('phone_number', 'like', "%{$termo}%")
                    ->orWhere('email', 'like', "%{$termo}%");
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
        $service->load(['customerUser', 'vendor.user', 'serviceType', 'schedule', 'candidates.vendor.user']);

        return ApiSuccessResponse::make([
            ...$this->present($service),
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
                    'responded_at' => optional($c->updated_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
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
             * O estado do matching. Sem isto não se sabe a diferença entre "não
             * apareceu ninguém" e "ninguém foi sequer perguntado" -- que é a
             * diferença entre um problema de rede e um problema de sistema.
             */
            'candidates' => [
                'notified' => (int) ($service->candidates_notified ?? 0),
                'accepted' => (int) ($service->candidates_accepted ?? 0),
                'declined' => (int) ($service->candidates_declined ?? 0),
                'expired' => (int) ($service->candidates_expired ?? 0),
            ],
        ];
    }
}
