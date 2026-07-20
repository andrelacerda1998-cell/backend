<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->callOnce(GenderSeeder::class);
        #$this->callOnce(UserSeeder::class);
        $this->callOnce(OperationAreasSeeder::class);
        $this->callOnce(ServicesTypesSeeder::class);
    }
}
