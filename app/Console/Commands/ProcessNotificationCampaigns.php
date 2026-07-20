<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNotificationCampaign;
use App\Models\NotificationCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessNotificationCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:process-campaigns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process active notification campaigns and send notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Processing notification campaigns...');

        $campaigns = NotificationCampaign::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->where(function ($query) {
                $query->whereNull('next_send_at')
                    ->orWhere('next_send_at', '<=', now());
            })
            ->get();

        Log::info('campaigns to process', ['ids' => $campaigns->pluck('id')->all()]);
        if ($campaigns->isEmpty()) {
            $this->info('No campaigns to process.');
            return 0;
        }

        $this->info("Found {$campaigns->count()} campaign(s) to process.");

        foreach ($campaigns as $campaign) {
            $this->info("Processing campaign: {$campaign->name}");
            Log::info("Processing campaign: {$campaign->name}");
            ProcessNotificationCampaign::dispatch($campaign);
        }

        $this->info('Campaigns queued for processing.');
        return 0;
    }
}
