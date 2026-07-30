<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Http\Request;
use OwenIt\Auditing\Models\Audit;

/**
 * Atividade — feed real de auditoria (tabela `audits`, owen-it/laravel-
 * auditing), em vez do registo fictício anterior. Só leitura, sem
 * equivalente direto no Filament (lá é por registo, via
 * AuditsRelationManager em cada recurso; aqui é um feed global).
 *
 * Filtrado a ações de STAFF (roles admin/super-admin): sem isto, o feed
 * ficava inundado por edições de rotina de clientes/técnicos nos seus
 * próprios dados (morada, perfil, dispositivo), que também passam pela
 * mesma tabela `audits` mas não são "atividade do backoffice".
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

    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = Audit::query()
            ->whereHas('user.roles', fn ($q) => $q->whereIn('name', ['admin', 'super-admin']))
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
                'who' => 'Sistema',
                'action' => trim("{$eventLabel} ".(self::ENTITY_LABELS[$type] ?? $type)),
                'entity' => '#'.$audit->auditable_id,
                'old_value' => null,
                'new_value' => null,
                'at' => $audit->created_at?->toIso8601String(),
            ];
        }
    }

    private function present(Audit $audit): array
    {
        $user = $audit->user;
        // Não usar user->name -- User::setNameAttribute() nunca grava a
        // coluna 'name' (bug pré-existente, ver nota em VendorDocumentController).
        $who = $user
            ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: 'Utilizador #'.$audit->user_id)
            : 'Sistema';

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
