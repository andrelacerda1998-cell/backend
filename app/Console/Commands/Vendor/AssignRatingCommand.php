<?php

namespace App\Console\Commands\Vendor;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\Vendor;
use Illuminate\Console\Command;

class AssignRatingCommand extends Command
{
    protected $signature = 'vendor:assign-rating';

    protected $description = 'Assign average rating to vendor in mass update';

    public function handle(): void
    {
        Vendor::chunk(5, function ($vendors) {
            foreach ($vendors as $vendor) {
                /**
                 * @var Vendor $vendor
                 */
                $vendor->searchable();
            }
        });
    }
}
