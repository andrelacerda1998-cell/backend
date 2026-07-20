<?php

namespace Database\Factories;

use App\Models\GeneralSettings\Gender;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
            'gender_id' => $this->faker->randomElement(Gender::pluck('id')->toArray()),
            'language' => 'pt',
            'date_birthday' => $this->faker->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d'),
            'nif' => $this->generateValidNif(),
            'phone_number' => $this->faker->phoneNumber(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    private function generateValidNif(): string
    {
        $firstDigit = $this->faker->randomElement([1, 2, 3, 5, 6, 7, 8, 9]);

        $nif = [$firstDigit];
        for ($i = 1; $i < 8; $i++) {
            $nif[] = $this->faker->numberBetween(0, 9);
        }

        $checkDigit = 0;
        for ($i = 0; $i < 8; $i++) {
            $checkDigit += $nif[$i] * (10 - $i - 1);
        }

        $checkDigit = 11 - ($checkDigit % 11);

        if ($checkDigit >= 10) {
            $checkDigit = 0;
        }

        $nif[] = $checkDigit;

        return implode('', $nif);
    }
}
