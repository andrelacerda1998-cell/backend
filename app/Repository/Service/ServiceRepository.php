<?php

namespace App\Repository\Service;

use App\Models\User;

class ServiceRepository
{
    public function getPendingPaidServices(User $user)
    {
        return $user->vendor->services()->pendingPaid()->get();
    }
}
