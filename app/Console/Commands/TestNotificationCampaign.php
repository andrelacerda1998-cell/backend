<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNotificationCampaign;
use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestNotificationCampaign extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:test-campaign {campaign? : The ID or name of the campaign to test} {--user= : Specific user ID to test with}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test a notification campaign by sending it immediately';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $campaignId = $this->argument('campaign');
        $userId = $this->option('user');

        if ($campaignId) {
            $campaign = NotificationCampaign::where('id', $campaignId)
                ->orWhere('name', 'like', "%{$campaignId}%")
                ->first();

            if (!$campaign) {
                $this->error("Campaign not found: {$campaignId}");
                return 1;
            }
        } else {
            $campaigns = NotificationCampaign::where('is_active', true)->get();

            if ($campaigns->isEmpty()) {
                $this->error('No active campaigns found.');
                return 1;
            }

            $campaignNames = $campaigns->pluck('name', 'id')->toArray();
            $campaignId = $this->choice('Select a campaign to test', $campaignNames);
            $campaign = $campaigns->firstWhere('id', $campaignId);
        }

        $this->info("Testing campaign: {$campaign->name}");

        // Check if campaign should send
        if (!$campaign->shouldSend()) {
            $this->warn('Campaign should not send based on current conditions.');
            $this->info('Checking conditions...');
            $this->info('  - Active: ' . ($campaign->is_active ? 'Yes' : 'No'));
            $this->info('  - Starts at: ' . ($campaign->starts_at ? $campaign->starts_at->format('Y-m-d H:i:s') : 'Not set'));
            $this->info('  - Ends at: ' . ($campaign->ends_at ? $campaign->ends_at->format('Y-m-d H:i:s') : 'Not set'));
            $this->info('  - Next send at: ' . ($campaign->next_send_at ? $campaign->next_send_at->format('Y-m-d H:i:s') : 'Not set'));

            if (!$this->confirm('Force send anyway?', false)) {
                return 0;
            }
        }

        // Get target users
        $query = User::query();

        if ($campaign->target_type === 'vendor') {
            $query->whereHas('vendor');
        } elseif ($campaign->target_type === 'customer') {
            $query->whereDoesntHave('vendor');
        }

        if (in_array($campaign->target_type, ['vendor', 'both']) && $campaign->user_status) {
            $query->whereHas('vendor', function ($q) use ($campaign) {
                if ($campaign->user_status === 'online') {
                    $q->where('status', 'Online');
                } elseif ($campaign->user_status === 'offline') {
                    $q->where('status', 'Offline');
                }
            });
        }

        $query->whereHas('devices');

        if ($userId) {
            $query->where('id', $userId);
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->error('No eligible users found for this campaign.');
            $this->info('Make sure:');
            Log::info('  - Users have devices registered (expo_token)');
            $this->info('  - Users match the target criteria');
            return 1;
        }

        Log::info("Found {$users->count()} eligible user(s)");

        if ($this->confirm('Send notification to all eligible users?', true)) {
            // Temporarily bypass shouldSend check
            $originalNextSend = $campaign->next_send_at;
            $campaign->next_send_at = null;

            try {
                ProcessNotificationCampaign::dispatchSync($campaign);
                Log::info('✅ Notifications sent successfully!');
            } catch (\Exception $e) {
                Log::alert('❌ Error sending notifications: ' . $e->getMessage());
                $this->error($e->getTraceAsString());
                return 1;
            } finally {
                $campaign->next_send_at = $originalNextSend;
            }
        }

        return 0;
    }
}
