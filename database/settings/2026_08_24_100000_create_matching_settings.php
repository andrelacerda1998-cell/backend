<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('matching.shortlist_size', 3);
        $this->migrator->add('matching.wave_size', 6);
        $this->migrator->add('matching.wave_interval_seconds', 45);
        $this->migrator->add('matching.max_waves', 3);
        $this->migrator->add('matching.vendor_response_seconds_immediate', 60);
        $this->migrator->add('matching.vendor_response_seconds_scheduled', 1800);
        $this->migrator->add('matching.customer_choice_seconds', 120);
        $this->migrator->add('matching.checkout_seconds', 300);
        $this->migrator->add('matching.rating_bands', [4.5, 4.0, 3.0]);
        $this->migrator->add('matching.new_vendor_min_ratings', 5);
        $this->migrator->add('matching.require_recent_activity_minutes', 15);
    }
};
