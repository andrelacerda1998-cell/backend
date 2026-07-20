<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use function Laravel\Prompts\text;
use function Laravel\Prompts\password;
use function Laravel\Prompts\form;
use function Laravel\Prompts\clear;

class CreateSuperAdminCommand extends Command
{
    protected $signature = 'create:super-admin';

    protected $description = 'Creates a super admin user with full system permissions.';
    private const string ROLE_SUPER_ADMIN = 'super-admin';

    public function handle(): void
    {
        $this->displayInitialMessage();

        $userData = $this->collectUserData();
        $userData['password'] = $this->validatePassword();
        $userData['email'] = $this->validateUniqueEmail();

        $admin = $this->createSuperAdmin($userData);

        $this->displaySuccessMessage($admin);
    }

    private function displayInitialMessage(): void
    {
        $this->info("Creating super admin user");
        $this->displayEnvironmentInfo();
    }

    private function displayEnvironmentInfo(): void
    {
        $this->warn('Environment: ' . app()->environment() . '.');
    }

    private function collectUserData(): array
    {
        $response = form()
            ->text('First name', required: true, name: 'first_name')
            ->text('Last name', required: true, name: 'last_name')
            ->text('Email', required: true, validate: ['email' => 'required|email|unique:users,email'], name: 'email')
            ->password('Password', required: true, validate: ['password' => 'min:8'], name: 'password')
            ->submit();

        clear();
        return $response;
    }

    private function validatePassword(): string
    {
        $password = form()->password('Password', required: true, validate: ['password' => 'min:8'], name: 'password')->submit()['password'];;
        $confirmPassword = '';

        while ($password !== $confirmPassword) {
            $confirmPassword = password('Confirm password', required: true, validate: ['password' => 'min:8']);
            if ($password !== $confirmPassword) {
                $this->warn('Passwords do not match. Please enter the same password.');
            }
        }

        return $password;
    }

    private function validateUniqueEmail(): string
    {
        $email = form()->text('Email', required: true, validate: ['email' => 'required|email|unique:users,email'], name: 'email')->submit()['email'];

        while (User::where('email', $email)->exists()) {
            $this->warn('Email already exists. Please enter a different email.');
            $email = text('Email', required: true, validate: ['email' => 'required|email|unique:users,email']);
        }

        clear();
        return $email;
    }

    private function createSuperAdmin(array $userData): User
    {
        $admin = User::create([
            'first_name' => $userData['first_name'],
            'last_name' => $userData['last_name'],
            'email' => $userData['email'],
            'password' => $userData['password'],
        ]);

        Role::where('name', self::ROLE_SUPER_ADMIN)
            ->firstOrCreate(['name' => self::ROLE_SUPER_ADMIN, 'guard_name' => 'web']);

        $admin->assignRole(self::ROLE_SUPER_ADMIN);

        return $admin;
    }

    private function displaySuccessMessage(User $admin): void
    {
        $admin->refresh();
        $this->displayEnvironmentInfo();
        $this->info('Super admin created successfully');
        $this->info('Name: ' . $admin->name);
        $this->info('Email: ' . $admin->email);
    }
}
