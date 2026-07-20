<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth:api']]);

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('service.vendor.{userID}', function ($user, int $userID) {
    return (int) $user->id === (int) $userID;
});

Broadcast::channel('service.customer.{userID}', function ($user, int $userID) {
    return (int) $user->id === (int) $userID;
});

Broadcast::channel('common.services.{serviceID}', function ($user, int $serviceID) {
    $service = App\Models\Service::find($serviceID);

    // Service::find pode devolver null e vendor é nullable → sem estas guardas, 500 no auth do canal.
    if (! $service) {
        return false;
    }

    return $service->customer_id === $user->id || $service->vendor?->user_id === $user->id;
});
