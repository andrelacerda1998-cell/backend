<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceExtra;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceExtra>
 */
class ServiceExtraFactory extends Factory
{
    protected $model = ServiceExtra::class;

    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'type' => 'time',
            'description' => null,
            'minutes' => 30,
            'amount' => 1500, // 15€
            'status' => 'pending',
        ];
    }

    public function part(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'part',
            'description' => 'Torneira monocomando',
            'minutes' => null,
            'amount' => 4500,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'resolved_at' => now(),
        ]);
    }
}
