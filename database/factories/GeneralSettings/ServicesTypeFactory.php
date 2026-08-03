<?php

namespace Database\Factories\GeneralSettings;

use App\Models\GeneralSettings\ServicesType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServicesType>
 */
class ServicesTypeFactory extends Factory
{
    protected $model = ServicesType::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'time' => 60,
            'starts_from' => 2000,
            'operation_area_id' => \Database\Factories\GeneralSettings\OperationAreaFactory::new(),
        ];
    }
}
