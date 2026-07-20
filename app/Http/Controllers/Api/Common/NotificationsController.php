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
                $databaseNotification->markAsRead();

                return [
                    ...$databaseNotification->data,
                    'id' => $databaseNotification->id,
                    // 'title' => __($databaseNotification->data['title']),
                    // 'body' => __($databaseNotification->data['body']),
                    // 'title' => $databaseNotification->getTranslation('title', $language),
                    'date' => $databaseNotification->created_at,
                    'read_at' => $databaseNotification->read_at
                ];
            })
            ->sortByDesc('date')
            ->take(20);

        return ApiSuccessResponse::make(['notifications' => $notifications, 'user' => $user]);
    }
}
