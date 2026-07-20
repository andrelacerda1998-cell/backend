<?php

namespace App\Console\Commands\Device;

use App\Models\Device;
use Illuminate\Console\Command;

class DeduplicateDeviceTokensCommand extends Command
{
    protected $signature = 'devices:deduplicate {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove duplicate expo push tokens, keeping only the most recent association per device.';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN — nothing will be deleted.');
        }

        // Find all tokens that appear in more than one account
        $duplicateTokens = Device::selectRaw('expo_token, COUNT(DISTINCT user_id) as accounts, COUNT(*) as total')
            ->groupBy('expo_token')
            ->havingRaw('COUNT(DISTINCT user_id) > 1')
            ->get();

        if ($duplicateTokens->isEmpty()) {
            $this->info('No duplicate tokens found.');

            return self::SUCCESS;
        }

        $this->info("Found {$duplicateTokens->count()} token(s) associated with multiple accounts.");
        $this->newLine();

        $totalDeleted = 0;

        foreach ($duplicateTokens as $duplicate) {
            // For each token, get all device records ordered by most recent first
            $devices = Device::where('expo_token', $duplicate->expo_token)
                ->with('user:id,name,email')
                ->orderByDesc('updated_at')
                ->get();

            $keep = $devices->first();
            $toDelete = $devices->skip(1);

            $this->line("Token: <fg=yellow>{$duplicate->expo_token}</>");
            $this->line("  Keep  → [{$keep->user?->name}] {$keep->user?->email} (updated {$keep->updated_at})");

            foreach ($toDelete as $device) {
                $this->line("  Remove → [{$device->user?->name}] {$device->user?->email} (updated {$device->updated_at})");

                if (! $isDryRun) {
                    $device->delete();
                    $totalDeleted++;
                } else {
                    $totalDeleted++;
                }
            }

            $this->newLine();
        }

        $action = $isDryRun ? 'Would delete' : 'Deleted';
        $this->info("{$action} {$totalDeleted} duplicate device record(s).");

        return self::SUCCESS;
    }
}
