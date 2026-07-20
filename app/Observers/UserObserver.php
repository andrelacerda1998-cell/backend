<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        $user->wallet()->create([
            'name' => 'Default Wallet',
        ]);
    }

    public function updating(User $user): void
    {
        if ($user->isDirty('phone_number')){
            $user->phone_number_verified_at = null;
        }
    }
}
