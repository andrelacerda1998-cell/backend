<?php

namespace App\Listeners;

use App\Events\Customer\ProfileCompletionNeeded;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendProfileCompletionPush implements ShouldQueue
{
    public function handle(ProfileCompletionNeeded $event): void
    {
        $user = $event->user;

        if (! $user->isPhoneOnly()) {
            return;
        }

        $user->notify(new \App\Notifications\Customer\ProfileCompletionNotification);
    }
}
