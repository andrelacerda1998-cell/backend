<?php

namespace Tests\Feature;

use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignLog;
use App\Models\NotificationCampaignOptOut;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCampaignMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeCampaign(): NotificationCampaign
    {
        return NotificationCampaign::create([
            'name' => 'Test Campaign',
            'title' => ['en' => 'Title', 'pt-pt' => 'Título'],
            'body' => ['en' => 'Body', 'pt-pt' => 'Corpo'],
            'target_type' => 'both',
            'frequency_type' => 'once',
            'is_active' => true,
        ]);
    }

    private function makeLog(NotificationCampaign $campaign, User $user, array $extra = []): NotificationCampaignLog
    {
        return NotificationCampaignLog::create(array_merge([
            'notification_campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'sent_at' => now(),
            'success' => true,
        ], $extra));
    }

    // --- Tracking: open ---

    public function test_open_tracking_sets_opened_at(): void
    {
        $user = $this->makeUser();
        $campaign = $this->makeCampaign();
        $log = $this->makeLog($campaign, $user);

        $response = $this->actingAs($user, 'api')
            ->postJson("/api/v1/common/notifications/campaign-log/{$log->id}/open");

        $response->assertOk();
        $this->assertNotNull($log->fresh()->opened_at);
    }

    public function test_open_tracking_is_idempotent(): void
    {
        $user = $this->makeUser();
        $campaign = $this->makeCampaign();
        $log = $this->makeLog($campaign, $user, ['opened_at' => now()->subMinutes(5)]);
        $original = $log->opened_at;

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/common/notifications/campaign-log/{$log->id}/open");

        $this->assertEquals($original->toDateTimeString(), $log->fresh()->opened_at->toDateTimeString());
    }

    public function test_open_tracking_forbidden_for_other_user(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $campaign = $this->makeCampaign();
        $log = $this->makeLog($campaign, $owner);

        $this->actingAs($other, 'api')
            ->postJson("/api/v1/common/notifications/campaign-log/{$log->id}/open")
            ->assertForbidden();
    }

    // --- Tracking: click ---

    public function test_click_tracking_sets_deep_link_clicked_at(): void
    {
        $user = $this->makeUser();
        $campaign = $this->makeCampaign();
        $log = $this->makeLog($campaign, $user);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/common/notifications/campaign-log/{$log->id}/click")
            ->assertOk();

        $this->assertNotNull($log->fresh()->deep_link_clicked_at);
    }

    public function test_click_tracking_forbidden_for_other_user(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $campaign = $this->makeCampaign();
        $log = $this->makeLog($campaign, $owner);

        $this->actingAs($other, 'api')
            ->postJson("/api/v1/common/notifications/campaign-log/{$log->id}/click")
            ->assertForbidden();
    }

    // --- Opt-out ---

    public function test_user_can_opt_out_globally(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/common/notifications/opt-out', ['campaign_id' => null])
            ->assertOk();

        $this->assertDatabaseHas('notification_campaign_opt_outs', [
            'user_id' => $user->id,
            'notification_campaign_id' => null,
        ]);
    }

    public function test_user_can_opt_out_per_campaign(): void
    {
        $user = $this->makeUser();
        $campaign = $this->makeCampaign();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/common/notifications/opt-out', ['campaign_id' => $campaign->id])
            ->assertOk();

        $this->assertDatabaseHas('notification_campaign_opt_outs', [
            'user_id' => $user->id,
            'notification_campaign_id' => $campaign->id,
        ]);
    }

    public function test_user_can_opt_back_in(): void
    {
        $user = $this->makeUser();
        $campaign = $this->makeCampaign();
        NotificationCampaignOptOut::create([
            'user_id' => $user->id,
            'notification_campaign_id' => $campaign->id,
        ]);

        $this->actingAs($user, 'api')
            ->deleteJson('/api/v1/common/notifications/opt-out', ['campaign_id' => $campaign->id])
            ->assertOk();

        $this->assertDatabaseMissing('notification_campaign_opt_outs', [
            'user_id' => $user->id,
            'notification_campaign_id' => $campaign->id,
        ]);
    }

    // --- Metric calculations ---

    public function test_open_rate_calculation(): void
    {
        $campaign = $this->makeCampaign();
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();

        $this->makeLog($campaign, $user1, ['opened_at' => now()]);
        $this->makeLog($campaign, $user2);

        $this->assertEquals(50.0, $campaign->openRate());
    }

    public function test_open_rate_zero_when_no_sends(): void
    {
        $campaign = $this->makeCampaign();
        $this->assertEquals(0.0, $campaign->openRate());
    }

    public function test_opt_out_rate_calculation(): void
    {
        $campaign = $this->makeCampaign();
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();

        $this->makeLog($campaign, $user1);
        $this->makeLog($campaign, $user2);
        NotificationCampaignOptOut::create(['user_id' => $user1->id, 'notification_campaign_id' => $campaign->id]);

        $this->assertEquals(50.0, $campaign->optOutRate());
    }
}
