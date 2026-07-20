<?php


use App\Models\GeneralSettings\SystemWallet;
use Bavix\Wallet\Models\Wallet;

if (!function_exists('system_wallet')) {
    /**
     * Retrieves or creates the system wallet.
     *
     * This function first attempts to fetch the "System Wallet" from the SystemWallet model.
     * If the wallet is not found, it creates a new wallet with specified attributes.
     *
     * @return Wallet The system wallet instance if found or created.
     */
    function system_wallet(): Wallet
    {
        $systemWallet = SystemWallet::firstOrCreate(['name' => 'System Wallet']);

        $wallet = $systemWallet->getWallet('system-wallet');

        if (!$wallet) {
            $wallet = $systemWallet->createWallet([
                'name' => 'System Wallet',
                'slug' => 'system-wallet',
                'meta' => ['description' => 'This is the main wallet for system-wide transactions.'],
            ]);
        }

        return $wallet;
    }
}
