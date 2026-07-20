<?php

namespace App\Console\Commands\dev;

use App\Enums\SmsType;
use App\Services\Common\SmsValidationService;
use Illuminate\Console\Command;
use Illuminate\Notifications\Facades\Vonage;
use Illuminate\Notifications\Notification;

class TestSmsCommand extends Command
{
    protected $signature = 'dev:test-sms';

    protected $description = 'Command description';

    public function handle(): void
    {
        $user = \App\Models\User::find(2);

        $service = new SmsValidationService($user);
        $service->sendValidationCode(SmsType::VERIFICATION);
    }
}
