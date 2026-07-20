<?php

namespace App\Console\Commands\dev;

use Illuminate\Console\Command;

class AddBalanceToMainWalletCommand extends Command
{
    protected $signature = 'dev:add-balance-to-main-wallet';

    protected $description = 'Command description';

    public function handle(): void
    {

        $amount = $this->ask('How much to add?', 100);

        if (is_string($amount) && ! is_numeric($amount)) {
            $this->error('Invalid amount');
            return;
        }

        $amount = $amount*100;

        system_wallet()->deposit($amount);

        $this->info('Done, has been added '.($amount/100).'€ to main wallet');
    }
}
