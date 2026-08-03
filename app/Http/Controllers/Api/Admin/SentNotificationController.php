<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Sent Notifications — equivalente ao Filament SentNotificationResource.
 * Só leitura: histórico do que já foi mesmo enviado a clientes/técnicos
 * (tabela `notifications`, Illuminate\Notifications\DatabaseNotification).
 * Distinto do tab "Push" em Marketing (que cria campanhas) -- isto é o log
 * de envios reais, sejam eles de campanhas push ou de qualquer outra
 * notificação do sistema (aprovação de documento, pagamento, etc.).
 */
class SentNotificationController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = DatabaseNotification::query()->with('notifiable');

        if ($search = $request->string('search')->trim()->value()) {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->whereHasMorph('notifiable', User::class, function ($uq) use ($like) {
                    $uq->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })->orWhere('data', 'like', $like);
            });
        }

        if ($type = $request->string('type')->trim()->value()) {
            $query->where('type', $type);
        }

        if ($readFilter = $request->string('read')->trim()->value()) {
            if ($readFilter === 'read') {
                $query->read();
            } elseif ($readFilter === 'unread') {
                $query->unread();
            }
        }

        if ($recipientType = $request->string('recipient_type')->trim()->value()) {
            if ($recipientType === 'vendor') {
                $query->whereHasMorph('notifiable', User::class, fn ($uq) => $uq->whereHas('vendor'));
            } elseif ($recipientType === 'customer') {
                $query->whereHasMorph('notifiable', User::class, fn ($uq) => $uq->whereDoesntHave('vendor'));
            }
        }

        $notifications = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($notifications->items())->map($this->presentSafely(...))->all(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /** Opções para o dropdown de filtro por tipo -- equivalente a Filament::typeOptions(). */
    public function types(): ApiSuccessResponse
    {
        $types = DatabaseNotification::query()
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->map(fn (string $type): array => [
                'value' => $type,
                'label' => class_basename($type),
            ])
            ->values()
            ->all();

        return ApiSuccessResponse::make(['items' => $types]);
    }

    /** Isola falhas de uma linha (notifiable apagado, payload corrompido) do resto do feed. */
    private function presentSafely(DatabaseNotification $notification): array
    {
        try {
            return $this->present($notification);
        } catch (\Throwable $e) {
            report($e);

            return [
                'id' => $notification->id,
                'recipient' => null,
                'recipient_type' => null,
                'type' => class_basename((string) $notification->type) ?: '?',
                'title' => '-',
                'body' => '-',
                'read' => $notification->read_at !== null,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ];
        }
    }

    private function present(DatabaseNotification $notification): array
    {
        $notifiable = $notification->notifiable;
        $isVendor = $notifiable instanceof User && $notifiable->isVendor();

        $recipient = null;
        if ($notifiable instanceof User) {
            $name = trim(($notifiable->first_name ?? '').' '.($notifiable->last_name ?? ''));
            $recipient = [
                'id' => $isVendor ? $notifiable->vendor?->id : $notifiable->id,
                'name' => $name ?: '-',
            ];
        }

        $data = is_array($notification->data) ? $notification->data : [];

        return [
            'id' => $notification->id,
            'recipient' => $recipient,
            'recipient_type' => $notifiable ? ($isVendor ? 'vendor' : 'customer') : null,
            'type' => class_basename((string) $notification->type) ?: '?',
            'title' => $data['title'] ?? '-',
            'body' => $data['body'] ?? '-',
            'read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
