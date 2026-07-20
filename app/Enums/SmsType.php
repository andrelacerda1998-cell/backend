<?php

namespace App\Enums;

enum SmsType: string
{
    case VERIFICATION = 'verification';
    case Login = 'login';
}
