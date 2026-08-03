<?php

namespace Database\Factories\GeneralSettings;

use App\Models\GeneralSettings\OperationArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationArea>
 */
class OperationAreaFactory extends Factory
{
    protected $model = OperationArea::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
        ];
    }
}
