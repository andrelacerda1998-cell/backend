<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\VendorDocuments;
use App\Models\Voucher;
use App\Services\Auditoria\StaffDoBackoffice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OwenIt\Auditing\Models\Audit;
use RwInteractive\PayshopSdk\Models\PaymentOrder;

/**
 * Atividade — feed real de auditoria (tabela `audits`, owen-it/laravel-
 * auditing), em vez do registo fictício anterior. Só leitura, sem
 * equivalente direto no Filament (lá é por registo, via
 * AuditsRelationManager em cada recurso; aqui é um feed global).
 *
 * Filtrado a ações de STAFF: sem isto, o feed ficava inundado por edições
 * de rotina de clientes/técnicos nos seus próprios dados (morada, perfil,
 * dispositivo), que também passam pela mesma tabela `audits` mas não são
 * "atividade do backoffice". Staff são duas coisas:
 *
 *  - quem tem conta no Laravel com o papel admin/super-admin (o Filament);
 *  - quem mexeu pelo backoffice novo, que fala com a API por um token
 *    partilhado e por isso não tem `user_id` — vem identificado em
 *    `staff_email`/`staff_nome` (ver StaffDoBackoffice). Antes destas
 *    colunas, essas alterações nem apareciam aqui.
 *
 * Filtros opcionais: `staff` (email), `auditable_type` (nome curto, ver
 * TIPOS), `auditable_id` e `event`.
 */
class AuditController extends Controller
{
    private const ENTITY_LABELS = [
        'ServicesType' => 'Tipo de serviço',
        'OperationArea' => 'Categoria',
        'Document' => 'Documento',
        'Voucher' => 'Voucher',
        'Vendor' => 'Técnico',
        'Service' => 'Serviço',
        'User' => 'Utilizador',
        'Address' => 'Morada',
        'Device' => 'Dispositivo',
        'Gender' => 'Género',
        'NotificationCampaign' => 'Campanha de notificação',
        'NotificationCampaignLog' => 'Registo de campanha',
        'Location' => 'Localização',
        'VendorDocuments' => 'Documento de técnico',
    ];

    private const EVENT_LABELS = [
        'created' => 'Criou',
        'updated' => 'Atualizou',
        'deleted' => 'Removeu',
        'restored' => 'Restaurou',
    ];

    /** Papéis que contam como staff nas contas do Laravel. */
    private const PAPEIS_DE_STAFF = ['admin', 'super-admin'];

    /**
     * Os nomes curtos que o filtro `auditable_type` aceita. O backoffice não
     * tem de conhecer o namespace de cada modelo, nem partir quando um muda
     * de sítio. Cliente e utilizador são o mesmo modelo: a conta de um
     * técnico também é um User (os dados do ofício estão no Vendor) — por
     * isso o nome que sai nas linhas é `user`, e `customer` é sinónimo.
     *
     * O PaymentOrder vem do SDK do Payshop e ainda não é auditado: o filtro
     * aceita-o, e devolve vazio até passar a ser.
     */
    private const TIPOS = [
        'user' => User::class,
        'customer' => User::class,
        'vendor' => Vendor::class,
        'service' => Service::class,
        'payment_order' => PaymentOrder::class,
        'services_type' => ServicesType::class,
        'operation_area' => OperationArea::class,
        'voucher' => Voucher::class,
        'document' => Document::class,
        'vendor_document' => VendorDocuments::class,
    ];

    public function index(Request $request): ApiSuccessResponse
    {
        $filtros = $request->validate([
            'staff' => ['nullable', 'string', 'max:'.StaffDoBackoffice::MAXIMO],
            'auditable_type' => ['nullable', 'string', Rule::in(array_keys(self::TIPOS))],
            'auditable_id' => ['nullable', 'integer', 'min:1'],
            'event' => ['nullable', 'string', Rule::in(array_keys(self::EVENT_LABELS))],
        ], [
            'auditable_type.in' => 'auditable_type aceita: '.implode(', ', array_keys(self::TIPOS)).'.',
            'event.in' => 'event aceita: '.implode(', ', array_keys(self::EVENT_LABELS)).'.',
        ]);

        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = Audit::query()
            ->where(fn (Builder $q) => $q
                // Pelo backoffice novo (token partilhado, sem user_id).
                ->whereNotNull('staff_email')
                // Pelas contas de staff do Laravel. Os ids primeiro, numa
                // consulta à parte: a condição fica um IN sobre o índice de
                // (user_id, user_type) em vez de um EXISTS por linha — e o
                // `whereHas('user.roles')` de antes ainda fazia um DISTINCT
                // sobre a tabela inteira para descobrir os tipos de utilizador.
                ->orWhere(fn (Builder $u) => $u
                    ->where('user_type', User::class)
                    ->whereIn('user_id', $this->idsDoStaff())))
            // Sem with(['user','auditable']): o histórico de 'audits' cobre
            // ~2 anos e alguns modelos já mudaram de sítio (ex: entraram no
            // namespace GeneralSettings). Uma linha antiga com um
            // auditable_type/user_type que já não existe como classe rebenta
            // a query de eager load PARA O LOTE INTEIRO desse tipo (não só a
            // linha em causa). Resolvendo por linha (lazy) + presentSafely,
            // uma linha problemática fica isolada e não derruba o feed todo.
            //
            // Desempate por 'id': dois audits do mesmo registo (criar +
            // atualizar) podem cair no mesmo segundo, e 'created_at' sozinho
            // não garante qual vem primeiro nesse caso.
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->filtrar($query, $filtros);

        $audits = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($audits->items())->map($this->presentSafely(...))->all(),
            'meta' => [
                'current_page' => $audits->currentPage(),
                'last_page' => $audits->lastPage(),
                'per_page' => $audits->perPage(),
                'total' => $audits->total(),
            ],
        ]);
    }

    /** @return list<int> */
    private function idsDoStaff(): array
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::PAPEIS_DE_STAFF))
            ->pluck('id')
            ->all();
    }

    private function filtrar(Builder $query, array $filtros): void
    {
        // A pessoa, seja pelo backoffice novo (staff_email), seja pela conta
        // dela no Laravel (o Filament): é a mesma pessoa a fazer coisas.
        if (filled($filtros['staff'] ?? null)) {
            $email = StaffDoBackoffice::email($filtros['staff']) ?? mb_strtolower(trim($filtros['staff']));

            $query->where(fn (Builder $q) => $q
                ->where('staff_email', $email)
                ->orWhere(fn (Builder $u) => $u
                    ->where('user_type', User::class)
                    ->whereIn('user_id', User::query()->where('email', $email)->pluck('id')->all())));
        }

        if (filled($filtros['auditable_type'] ?? null)) {
            $query->where('auditable_type', self::TIPOS[$filtros['auditable_type']]);
        }

        if (filled($filtros['auditable_id'] ?? null)) {
            $query->where('auditable_id', (int) $filtros['auditable_id']);
        }

        if (filled($filtros['event'] ?? null)) {
            $query->where('event', $filtros['event']);
        }
    }

    /** Isola falhas de uma linha (classe antiga inexistente, dados corrompidos) do resto do feed. */
    private function presentSafely(Audit $audit): array
    {
        try {
            return $this->present($audit);
        } catch (\Throwable $e) {
            report($e);

            $type = class_basename((string) $audit->auditable_type) ?: '?';
            $eventLabel = self::EVENT_LABELS[$audit->event] ?? ucfirst((string) $audit->event);

            return [
                'id' => $audit->id,
                'who' => $audit->staff_nome ?: ($audit->staff_email ?: 'Sistema'),
                'action' => trim("{$eventLabel} ".(self::ENTITY_LABELS[$type] ?? $type)),
                'entity' => '#'.$audit->auditable_id,
                'old_value' => null,
                'new_value' => null,
                'at' => $audit->created_at?->toIso8601String(),
                ...$this->campos($audit),
            ];
        }
    }

    /** O que identifica a linha para os filtros, e quem do staff a fez. */
    private function campos(Audit $audit): array
    {
        $classe = (string) $audit->auditable_type;

        return [
            'event' => $audit->event,
            // O nome curto que o filtro aceita; uma classe sem nome curto fica
            // com o nome dela, sem namespace.
            'auditable_type' => array_search($classe, self::TIPOS, true) ?: (class_basename($classe) ?: null),
            'auditable_id' => $audit->auditable_id !== null ? (int) $audit->auditable_id : null,
            'staff_email' => $audit->staff_email,
            'staff_nome' => $audit->staff_nome,
        ];
    }

    private function present(Audit $audit): array
    {
        $user = $audit->user;
        // Não usar user->name -- User::setNameAttribute() nunca grava a
        // coluna 'name' (bug pré-existente, ver nota em VendorDocumentController).
        //
        // Sem conta do Laravel, quem fez foi o staff do backoffice novo (se o
        // backoffice o disse) — só sem nada disso é que é o "Sistema".
        $who = $user
            ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: 'Utilizador #'.$audit->user_id)
            : ($audit->staff_nome ?: ($audit->staff_email ?: 'Sistema'));

        $type = class_basename((string) $audit->auditable_type);
        $entityLabel = self::ENTITY_LABELS[$type] ?? $type;
        $eventLabel = self::EVENT_LABELS[$audit->event] ?? ucfirst((string) $audit->event);

        $entityName = $this->resolveEntityName($audit->auditable);
        [$oldValue, $newValue] = $this->summarizeChanges((array) $audit->old_values, (array) $audit->new_values);

        return [
            'id' => $audit->id,
            'who' => $who,
            'action' => trim("{$eventLabel} {$entityLabel}"),
            'entity' => $entityName ?? ('#'.$audit->auditable_id),
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'at' => $audit->created_at?->toIso8601String(),
            ...$this->campos($audit),
        ];
    }

    private function resolveEntityName($model): ?string
    {
        if (! $model) {
            return null;
        }
        if (isset($model->name) && is_string($model->name) && $model->name !== '') {
            return $model->name;
        }
        if (isset($model->first_name) || isset($model->last_name)) {
            $name = trim(($model->first_name ?? '').' '.($model->last_name ?? ''));

            return $name ?: null;
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?string} [oldValue, newValue] */
    private function summarizeChanges(array $old, array $new): array
    {
        $keys = array_keys($new ?: $old);
        if (empty($keys)) {
            return [null, null];
        }
        if (count($keys) === 1) {
            $key = $keys[0];

            return [$this->stringify($old[$key] ?? null), $this->stringify($new[$key] ?? null)];
        }

        return [null, implode(', ', $keys)];
    }

    private function stringify($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'sim' : 'não';
        }
        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}
