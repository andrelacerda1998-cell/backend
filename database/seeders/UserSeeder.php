<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (! User::where('email', 'admin@example.com')->exists()) {
            $admin = User::factory()->create([
                'first_name' => 'Test',
                'last_name' => 'Admin',
                'email' => 'admin@example.com',
            ]);

            Role::firstOrCreate(['name' => 'admin']);

            $admin->assignRole('admin');
        }

        if (! User::where('email', 'user@vendor.com')->exists()) {
            $user = User::factory()->create([
                'first_name' => 'Test',
                'last_name' => 'Vendor',
                'email' => 'user@vendor.com',
                'password' => Hash::make('password12345'),
            ]);

            // Role::firstOrCreate(['name'=> 'vendor']);

            $vendor = $user->vendor()->create([
                'username' => 'test_vendor',
            ]);

            // $vendor->operationAreas()->sync($request->input('operation_areas'));
        }

        if (! User::where('email', 'user@customer.com')->exists()) {
            $user = User::factory()->create([
                'first_name' => 'Test',
                'last_name' => 'Customer',
                'email' => 'user@customer.com',
                'password' => Hash::make('password12345'),
            ]);

            // Role::firstOrCreate(['name'=> 'customer']);
        }
    }
}
