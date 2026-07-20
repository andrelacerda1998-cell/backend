<?php

namespace App\Enums\Services;

enum PaymentStatus: string
{
    case PENDING = 'Pending';
    case PAID = 'Paid';

    case CANCELED = 'Canceled';
    case REFUNDED = 'Refunded';
}
