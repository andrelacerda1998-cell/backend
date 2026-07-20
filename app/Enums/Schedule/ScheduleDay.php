<?php

namespace App\Enums\Schedule;

enum ScheduleDay: string
{
    case MONDAY = 'Mo';
    case TUESDAY = 'Tu';
    case WEDNESDAY = 'We';
    case THURSDAY = 'Th';
    case FRIDAY = 'Fr';
    case SATURDAY = 'Sa';
    case SUNDAY = 'Su';
}
