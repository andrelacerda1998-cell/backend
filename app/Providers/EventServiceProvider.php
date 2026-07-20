<?php

namespace App\Providers;

use App\Events\Customer\ProfileCompletionNeeded;
use App\Listeners\SendProfileCompletionPush;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ProfileCompletionNeeded::class => [
            SendProfileCompletionPush::class,
        ],
    ];
}
