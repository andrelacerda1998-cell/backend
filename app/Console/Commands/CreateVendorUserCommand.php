<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Common\PhoneLoginSmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use function Laravel\Prompts\clear;
use function Laravel\Prompts\form;
use function Laravel\Prompts\password;

class CreateVendorUserCommand extends Command
{
    protected $signature = 'create:vendor-user';

    protected $description = 'Creates a professional vendor user with fixed InvoiceXpress credentials from config.';

    public function handle(): void
    {
        $this->info('Creating professional vendor user');
        $this->warn('Environment: ' . app()->environment() . '.');

        $userData = $this->collectUserData();
        $vendorData = $this->collectVendorData();

        $user = $this->createUser($userData);
        $vendor = $this->createVendor($user, $vendorData);

        $this->displaySuccessMessage($user, $vendor);
    }

    private function collectUserData(): array
    {
        $data = form()
            ->text('First name', required: true, name: 'first_name')
            ->text('Last name', required: true, name: 'last_name')
            ->text('Email', required: true, validate: ['email' => 'required|email|unique:users,email'], name: 'email')
            ->text('NIF', required: true, validate: ['nif' => 'required|digits:9'], name: 'nif')
            ->text('Phone number', required: true, name: 'phone_number')
            ->submit();

        clear();

        $pass = password('Password', required: true, validate: ['password' => 'min:8']);
        $confirm = '';

        while ($pass !== $confirm) {
            $confirm = password('Confirm password', required: true, validate: ['password' => 'min:8']);
            if ($pass !== $confirm) {
                $this->warn('Passwords do not match. Please try again.');
            }
        }

        clear();

        return array_merge($data, ['password' => $pass]);
    }

    private function collectVendorData(): array
    {
        return form()
            ->text('Username', required: true, validate: ['username' => 'required|unique:vendors,username'], name: 'username')
            ->text('Company name', required: true, name: 'company_name')
            ->text('IBAN', required: true, name: 'iban')
            ->text('Price rate (cents per hour)', required: true, default: '1000', validate: ['price_rate' => 'required|integer|min:1'], name: 'price_rate')
            ->submit();
    }

    private function createUser(array $userData): User
    {
        return User::create([
            'first_name' => $userData['first_name'],
            'last_name' => $userData['last_name'],
            'email' => $userData['email'],
            'password' => Hash::make($userData['password']),
            'nif' => $userData['nif'],
            'phone_number' => PhoneLoginSmsService::normalizePhoneNumber($userData['phone_number']),
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
            'language' => 'pt-pt',
        ]);
    }

    /** @return \App\Models\Vendor */
    private function createVendor(User $user, array $vendorData): \App\Models\Vendor
    {
        return $user->vendor()->create([
            'username' => $vendorData['username'],
            'company_name' => $vendorData['company_name'],
            'iban' => $vendorData['iban'],
            'price_rate' => (int) $vendorData['price_rate'],
            'invoice_workspace' => 'rwinteractive',
            'invoice_account_id' => 152518,
            'auth_token' => 'a22c3d79790ba5a70ae41f59a9968b669ad12d06',
        ]);
    }

    /** @param \App\Models\Vendor $vendor */
    private function displaySuccessMessage(User $user, \App\Models\Vendor $vendor): void
    {
        $user->refresh();
        $vendor->refresh();

        $this->warn('Environment: ' . app()->environment() . '.');
        $this->info('Vendor user created successfully.');
        $this->newLine();
        $this->line('  Name:            ' . $user->name);
        $this->line('  Email:           ' . $user->email);
        $this->line('  NIF:             ' . $user->nif);
        $this->line('  Phone:           ' . $user->phone_number);
        $this->line('  Username:        ' . $vendor->username);
        $this->line('  Company:         ' . $vendor->company_name);
        $this->line('  IBAN:            ' . $vendor->iban);
        $this->line('  Price rate:      ' . $vendor->price_rate . ' cents/h');
        $this->newLine();
        $this->info('InvoiceXpress data (from config):');
        $this->line('  Workspace:       ' . $vendor->invoice_workspace);
        $this->line('  Account ID:      ' . $vendor->invoice_account_id);
        $this->line('  Auth token set:  ' . ($vendor->auth_token ? 'yes' : 'no'));
    }
}