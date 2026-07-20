<?php

use App\Models\NotificationCampaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Campaign for online vendors - reminder to stay attentive
        NotificationCampaign::create([
            'name' => 'Vendor Online Reminder',
            'title' => [
                'en' => 'Stay Attentive',
                'pt-pt' => 'Mantenha-se Atento',
            ],
            'body' => [
                'en' => 'You are online. Stay attentive to new service requests!',
                'pt-pt' => 'Está online. Mantenha-se atento a novos pedidos de serviço!',
            ],
            'target_type' => 'vendor',
            'user_status' => 'online',
            'frequency_type' => 'custom',
            'frequency_value' => 1, // Every 1 hour
            'frequency_unit' => 'hours',
            'is_active' => true,
            'starts_at' => now(),
            'ends_at' => null,
            'next_send_at' => now()->addHour(),
        ]);

        // Campaign for offline vendors - reminder to stay active
        NotificationCampaign::create([
            'name' => 'Vendor Offline Reminder',
            'title' => [
                'en' => 'Stay Active',
                'pt-pt' => 'Mantenha-se Ativo',
            ],
            'body' => [
                'en' => 'You are offline. Come back online to receive new service requests!',
                'pt-pt' => 'Está offline. Volte a ficar online para receber novos pedidos de serviço!',
            ],
            'target_type' => 'vendor',
            'user_status' => 'offline',
            'frequency_type' => 'custom',
            'frequency_value' => 2, // Every 2 hours
            'frequency_unit' => 'hours',
            'is_active' => true,
            'starts_at' => now(),
            'ends_at' => null,
            'next_send_at' => now()->addHours(2),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        NotificationCampaign::whereIn('name', [
            'Vendor Online Reminder',
            'Vendor Offline Reminder',
        ])->delete();
    }
};
