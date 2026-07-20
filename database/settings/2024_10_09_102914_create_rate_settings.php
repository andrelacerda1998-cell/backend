<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('services.daytime', 100);
        $this->migrator->add('services.evening', 120);
        $this->migrator->add('services.night', 150);
        $this->migrator->add('services.late_night', 190);
        $this->migrator->add('services.kilometer_price', 80);
        $this->migrator->add('services.system_commission', 25);
    }
};
