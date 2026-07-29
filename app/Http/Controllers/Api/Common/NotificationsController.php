<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Notifications\DatabaseNotification;

class NotificationsController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();
        $language = $user->language;

        $notifications = $user->notifications
            ->transform(function (DatabaseNotification $databaseNotification) use ($language) {
                // Guardar o estado ANTES de marcar como lida: senão a app nunca
                // consegue distinguir as notificações novas das já vistas.
                $wasReadAt = $databaseNotification->read_at;

                $databaseNotification->markAsRead();

                return [
                    ...$databaseNotification->data,
                    'id' => $databaseNotification->id,
                    // 'title' => __($databaseNotification->data['title']),
                    // 'body' => __($databaseNotification->data['body']),
                    // 'title' => $databaseNotification->getTranslation('title', $language),
                    'date' => $databaseNotification->created_at,
                    'read_at' => $wasReadAt
                ];
            })
            ->sortByDesc('date')
            ->take(20);

        return ApiSuccessResponse::make(['notifications' => $notifications, 'user' => $user]);
    }
}
