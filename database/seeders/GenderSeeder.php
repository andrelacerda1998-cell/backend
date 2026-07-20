<?php

namespace Database\Seeders;

use App\Models\GeneralSettings\Gender;
use Illuminate\Database\Seeder;

class GenderSeeder extends Seeder
{
    public function run(): void
    {
        $genders = [
            ['name' => 'Male'],
            ['name' => 'Female'],
            ['name' => 'Non-binary'],
            ['name' => 'Transgender Male'],
            ['name' => 'Transgender Female'],
            ['name' => 'Genderqueer'],
            ['name' => 'Genderfluid'],
            ['name' => 'Agender'],
            ['name' => 'Bigender'],
            ['name' => 'Two-Spirit'],
            ['name' => 'Pangender'],
            ['name' => 'Demiboy'],
            ['name' => 'Demigirl'],
            ['name' => 'Androgyne'],
            ['name' => 'Neutrois'],
            ['name' => 'Intersex'],
            ['name' => 'Questioning'],
            ['name' => 'Other'],
            ['name' => 'Prefer not to say'],
        ];

        foreach ($genders as $gender) {
            Gender::firstOrCreate(['name' => $gender['name']]);
        }
    }
}
