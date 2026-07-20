<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class RateSettings extends Settings
{
    public int $daytime;

    public int $evening;

    public int $night;

    public int $late_night;
    public int $midnight;

    public int $kilometer_price;

    public int $system_commission;

    public static function group(): string
    {
        return 'services';
    }
}
