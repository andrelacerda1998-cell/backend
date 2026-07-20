<?php

// Quick inline debug - run after test 1 and 2

namespace Tests\Feature;

use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_a(): void
    {
        $user = User::factory()->create();
        $campaign = NotificationCampaign::create([
            'name' => 'T', 'title' => ['en' => 'T'], 'body' => ['en' => 'B'],
            'target_type' => 'both', 'frequency_type' => 'once', 'is_active' => true,
        ]);
        $log = NotificationCampaignLog::create([
            'notification_campaign_id' => $campaign->id,
            'user_id' => $user->id, 'sent_at' => now(), 'success' => true,
        ]);
        $r = $this->actingAs($user, 'api')->postJson("/api/v1/common/notifications/campaign-log/{$log->id}/open");
        echo "\nTest A - User: {$user->id}, Log: {$log->id}, Status: {$r->status()}\n";
        $r->assertOk();
    }

    public function test_b(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $campaign = NotificationCampaign::create([
            'name' => 'T', 'title' => ['en' => 'T'], 'body' => ['en' => 'B'],
            'target_type' => 'both', 'frequency_type' => 'once', 'is_active' => true,
        ]);
        $log = NotificationCampaignLog::create([
            'notification_campaign_id' => $campaign->id,
            'user_id' => $owner->id, 'sent_at' => now(), 'success' => true,
        ]);
        $txBefore = \DB::transactionLevel();
        $found = NotificationCampaignLog::find($log->id);
        echo "\nTest B - Owner: {$owner->id}, Other: {$other->id}, Log: {$log->id}, Found: ".($found ? 'YES' : 'NO').", TX before: {$txBefore}\n";
        $r = $this->actingAs($other, 'api')
            ->postJson("/api/v1/common/notifications/campaign-log/{$log->id}/open");
        $txAfter = \DB::transactionLevel();
        echo "Test B - Status: {$r->status()}, TX after: {$txAfter}, Found after: ".(NotificationCampaignLog::find($log->id) ? 'YES' : 'NO')."\n";
        $r->assertForbidden();
    }
}
