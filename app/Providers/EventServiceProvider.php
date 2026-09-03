<?php

namespace App\Providers;

use App\Events\Customer\ProfileCompletionNeeded;
use App\Listeners\PruneUnregisteredExpoToken;
use App\Listeners\SendProfileCompletionPush;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ProfileCompletionNeeded::class => [
            SendProfileCompletionPush::class,
        ],
        // Poda tokens de push mortos quando a Expo devolve DeviceNotRegistered.
        NotificationFailed::class => [
            PruneUnregisteredExpoToken::class,
        ],
    ];
}
