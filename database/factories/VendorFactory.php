<?php

namespace Database\Factories;

use App\Enums\Vendors\StatusVendor;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => StatusVendor::ONLINE,
            'price_rate' => $this->faker->randomFloat(2, 10, 30),
            'username' => $this->faker->unique()->userName(),
        ];
    }
}
