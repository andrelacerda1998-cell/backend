<?php

namespace App\Providers;

use App\Events\Customer\ProfileCompletionNeeded;
use App\Listeners\PruneUnregisteredExpoToken;
use App\Listeners\RecordExpoDeliveryFailure;
use App\Listeners\SendProfileCompletionPush;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Notifications\Events\NotificationFailed;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ProfileCompletionNeeded::class => [
            SendProfileCompletionPush::class,
        ],
        // Poda tokens de push mortos quando a Expo devolve DeviceNotRegistered.
        NotificationFailed::class => [
            // Guarda o que a Expo recusou: sem isto uma campanha com 100% de
            // recusas ficava registada como 100% enviada.
            RecordExpoDeliveryFailure::class,
            PruneUnregisteredExpoToken::class,
        ],
    ];
}
