<?php

namespace App\Console\Commands\dev;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MoveMediaToLocalCommand extends Command
{
    protected $signature = 'media:move-to-local {--dry-run : Only show what would be moved, do not execute.} {--full-directory : Move the full directory including original, conversions and responsive images}';

    protected $description = 'Move all media files to the local disk';

    public function handle(): void
    {
        $this->info('Processing media...');

        $mediaItems = Media::where('disk', 'public')->get();
        $bar = $this->output->createProgressBar($mediaItems->count());

        foreach ($mediaItems as $media) {
            $bar->advance();

            $fromDisk = $media->disk;
            $toDisk   = 'local';

            if ($this->option('full-directory')) {
                $mediaDirectory = $media->id;
                $files = Storage::disk($fromDisk)->allFiles($mediaDirectory);

                foreach ($files as $file) {
                    if ($this->option('dry-run')) {
                        $this->line("\n[DRY RUN] {$file} ({$fromDisk} → {$toDisk})");
                        continue;
                    }

                    if (Storage::disk($fromDisk)->exists($file)) {
                        $fileContents = Storage::disk($fromDisk)->get($file);
                        Storage::disk($toDisk)->put($file, $fileContents);
                        Storage::disk($fromDisk)->delete($file);
                    } else {
                        $this->warn("\nFile not found: {$file}");
                    }
                }
            } else {
                $path = $media->getPathRelativeToRoot();

                if ($this->option('dry-run')) {
                    $this->line("\n[DRY RUN] {$path} ({$fromDisk} → {$toDisk})");
                    continue;
                }

                if (Storage::disk($fromDisk)->exists($path)) {
                    $file = Storage::disk($fromDisk)->get($path);
                    Storage::disk($toDisk)->put($path, $file);
                    Storage::disk($fromDisk)->delete($path);
                } else {
                    $this->warn("\nFile not found: {$path}");
                }
            }

            $media->disk = $toDisk;
            $media->conversions_disk = $toDisk;
            $media->save();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Process completed!');
    }
}
