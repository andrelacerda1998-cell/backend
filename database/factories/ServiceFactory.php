<?php

namespace Database\Factories;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 *
 * Serviço mínimo para os testes de extras: já ARRIVED (a decorrer) e pago, com
 * amount/amount_for_vendor definidos (comissão implícita de 25%, igual à regra
 * de negócio do produto) — é o estado em que os extras fazem sentido existir.
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $amount = $this->faker->numberBetween(3000, 12000); // 30€–120€, em cêntimos

        return [
            'customer_id' => User::factory(),
            'vendor_id' => Vendor::factory(),
            'services_type_id' => ServicesType::factory(),
            'status' => ServiceStatus::ARRIVED,
            'payment_status' => PaymentStatus::PAID,
            'amount' => $amount,
            'amount_for_vendor' => (int) round($amount * 0.75),
            'is_test' => false,
            'distance' => '2.5',
        ];
    }

    /** Serviço de teste — ChargeServiceExtra dispensa a cobrança dos extras (not_required). */
    public function test(): static
    {
        return $this->state(fn (array $attributes) => ['is_test' => true]);
    }
}
