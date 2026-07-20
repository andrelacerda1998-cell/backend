<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class DevLoginCommand extends Command
{
    protected $signature = 'dev:login {userId : The ID of the user to login as}';

    protected $description = 'Generate a JWT token for dev login by user ID';

    public function handle(): int
    {
        $userId = $this->argument('userId');

        $user = User::find($userId);

        if (! $user) {
            $this->error("User with ID {$userId} not found");
            return Command::FAILURE;
        }

        $token = auth('api')->login($user);

        $this->line(base64_encode($token));

        return Command::SUCCESS;
    }
}
