<?php

namespace App\Trait\Services;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Exceptions\Api\Customer\CustomerCantRequestServices;
use App\Exceptions\Api\Customer\CustomerDontHaveMainAddress;
use App\Exceptions\Api\Vendor\VendorCantAcceptServices;
use App\Models\Address;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Services\RateService;
use App\Trait\GeoAddress;

trait CalculateServicePriceForCustomer
{
    use GeoAddress, HasVendorDistance;

    /**
     * Minutos de trabalho que o pedido representa.
     *
     * ESTA é a alavanca das unidades. O preço é
     *   (tarifa_hora × duração) + (preço_km × distância)
     * e só a PRIMEIRA parcela é que escala: duas torneiras na mesma visita são
     * o dobro do trabalho, mas uma só deslocação. Multiplicar o total daria
     * duas deslocações a quem só teve uma — e um cliente atento nota.
     *
     * Como só se mexe nos minutos, o pagamento ao técnico (calculateForVendor,
     * que parte do mesmo tempo) e a duração da marcação acompanham sozinhos.
     *
     * Se algum dia a regra passar a ser "o preço duplica e ponto", troca-se
     * aqui por uma multiplicação do total — em nenhum outro sítio.
     */
    protected function effectiveMinutes(ServicesType $serviceType, int $quantity = 1): int
    {
        return (int) round($serviceType->time * max(1, $quantity));
    }

    protected function calculateServicePrice(
        ServicesType $serviceType,
        AddressCoordinatesDTO|Address $address,
        Vendor $vendor,
        bool $isScheduled = false,
        int $quantity = 1
    ): float {
        $rateService = app(RateService::class);

        $hourlyRate = $vendor->getRawOriginal('price_rate');
        $timeService = $this->effectiveMinutes($serviceType, $quantity);

        if ($isScheduled) {
            $customerCoords = $address instanceof AddressCoordinatesDTO
                ? $address
                : AddressCoordinatesDTO::fromAddress($address);

            $distance = $this->calculateVendorDistance($vendor, $customerCoords);

            return $rateService->calculateForCustomerForSchedule($hourlyRate, $timeService, $distance);
        }

        $distance = $this->calculateVendorDistanceInstantService($vendor, $address);

        return $rateService->calculateForCustomerInstantService($hourlyRate, $timeService, $distance);
    }

    /**
     * Compute the customer price and the vendor payout from a SINGLE distance
     * measurement, so the two values can never diverge.
     *
     * Distance rule:
     *  - Instant service: vendor current GPS location -> customer.
     *  - Scheduled service: vendor schedule/service address -> service location.
     *
     * Both `customer_amount` and `vendor_amount` are integer cents, VAT included.
     * `distance` is the single measurement used for both, so display/pricing stay in sync.
     *
     * @return array{customer_amount: int, vendor_amount: int, travel_amount: int, distance: float|int}
     */
    protected function calculatePrices(
        ServicesType $serviceType,
        AddressCoordinatesDTO|Address $address,
        Vendor $vendor,
        bool $isScheduled = false,
        int $quantity = 1
    ): array {
        $rateService = app(RateService::class);

        $hourlyRate = $vendor->getRawOriginal('price_rate');
        $timeService = $this->effectiveMinutes($serviceType, $quantity);

        if ($isScheduled) {
            $customerCoords = $address instanceof AddressCoordinatesDTO
                ? $address
                : AddressCoordinatesDTO::fromAddress($address);

            $distance = $this->calculateVendorDistance($vendor, $customerCoords);
            $customerAmount = $rateService->calculateForCustomerForSchedule($hourlyRate, $timeService, $distance);
        } else {
            $distance = $this->calculateVendorDistanceInstantService($vendor, $address);
            $customerAmount = $rateService->calculateForCustomerInstantService($hourlyRate, $timeService, $distance);
        }

        $vendorAmount = $rateService->calculateForVendor($hourlyRate, $timeService, $distance);
        $travelAmount = $rateService->calculateForCustomerTravelPortion($hourlyRate, $distance, $isScheduled);

        return [
            'customer_amount' => (int) round($customerAmount),
            'vendor_amount' => (int) round($vendorAmount),
            'travel_amount' => (int) round($travelAmount),
            'distance' => $distance,
        ];
    }

    protected function calculateGuestPrice(Vendor $vendor, ServicesType $serviceType, float $latitude, float $longitude, int $quantity = 1): array
    {
        $guestAddress = new AddressCoordinatesDTO($latitude, $longitude);

        $rateService = app(RateService::class);
        $hourlyRate = $vendor->getRawOriginal('price_rate');
        $timeService = $this->effectiveMinutes($serviceType, $quantity);
        $distance = $this->calculateVendorDistanceInstantService($vendor, $guestAddress);
        $amount = $rateService->calculateForCustomerInstantService($hourlyRate, $timeService, $distance);
        $travelAmount = (int) round($rateService->calculateForCustomerTravelPortion($hourlyRate, $distance, false));

        return [
            'amount' => $amount,
            'amount_formated' => number_format($amount / 100, 2, '.', ' '),
            'travel_amount' => $travelAmount,
            'travel_amount_formated' => number_format($travelAmount / 100, 2, '.', ' '),
            'distance' => $distance,
            'original_amount' => $amount,
            'original_amount_formated' => number_format($amount / 100, 2, '.', ' '),
            'discount_amount' => 0,
            'discount_amount_formated' => '0.00',
            'balance' => 0,
            'balance_formated' => '0.00',
            'value_for_payment' => $amount,
            'value_for_payment_formated' => number_format($amount / 100, 2, '.', ' '),
            'balance_after_payment' => 0,
            'balance_after_payment_formated' => '0.00',
            'balance_total_used' => 0,
            'balance_total_used_formated' => '0.00',
        ];
    }

    /**
     * @throws CustomerCantRequestServices
     */
    private function fetchCustomer()
    {
        $customer = auth('api')->user();

        // Telemóvel por verificar tem mensagem própria: a genérica fala do
        // cliente na terceira pessoa e não diz o que falta, o que deixa quem
        // está a pagar num beco sem saída. Aqui há um passo concreto a dar.
        if (! $customer->hasVerifiedPhoneNumber()) {
            throw new CustomerCantRequestServices('exceptions.services.verify_phone_to_request');
        }

        if (! $customer->can_request_service) {
            throw new CustomerCantRequestServices;
        }

        return $customer;
    }

    /**
     * @throws VendorCantAcceptServices
     * @throws \Exception
     */
    private function findVendor($vendorId, bool $scheduled = false): Vendor
    {
        $vendor = Vendor::findOrFail($vendorId);
        $customer = auth('api')->user();

        $isVendorTest = (bool) $vendor->user->is_test;
        $isCustomerTest = (bool) ($customer?->is_test ?? false);

        if ($isVendorTest !== $isCustomerTest) {
            throw new \Exception('Test users can only request services from test vendors', 403);
        }

        if (! $scheduled && ! $vendor->can_accept_service) {
            throw new VendorCantAcceptServices;
        }

        return $vendor;
    }

    /**
     * @throws CustomerDontHaveMainAddress
     */
    private function fetchCustomerMainAddress($customer): Address
    {
        $address = $customer->addresses()->where('main_address', true)->first();
        if (! $address) {
            throw new CustomerDontHaveMainAddress;
        }

        return $address;
    }

    /**
     * Morada escolhida pelo cliente (multi-morada). Tem de ser dele; se não
     * existir/não for dele, cai na principal para nunca criar um serviço sem
     * morada.
     *
     * @throws CustomerDontHaveMainAddress
     */
    private function fetchCustomerAddressById($customer, int $addressId): Address
    {
        $address = $customer->addresses()->whereKey($addressId)->first();

        return $address ?? $this->fetchCustomerMainAddress($customer);
    }

    /**
     * @throws CustomerCantRequestServices
     */
    private function getCustomer()
    {
        $customer = auth('api')->user();
        if (! $customer->can_request_service) {
            throw new CustomerCantRequestServices;
        }

        return $customer;
    }

    /**
     * @throws CustomerDontHaveMainAddress
     * @throws CustomerCantRequestServices
     */
    protected function calculateTransaction(
        $vendor,
        ServicesType $serviceType,
        bool $isScheduled = false,
        ?Voucher $voucher = null, bool $isGuest = false, ?array $address = null, int $quantity = 1): array
    {

        if ($isGuest) {
            $coordinates = $this->getCoordinates($address);
            $address = new AddressCoordinatesDTO($coordinates['lat'], $coordinates['lng']);
        } else {
            $customer = $this->fetchCustomer();

            $address = $this->fetchCustomerMainAddress($customer);
        }

        $prices = $this->calculatePrices($serviceType, $address, $vendor, $isScheduled, $quantity);

        return $this->buildTransactionTotals(
            $isGuest ? null : $customer,
            $prices['customer_amount'],
            $prices['vendor_amount'],
            $prices['distance'],
            $voucher,
            $isGuest,
            $prices['travel_amount'],
        );
    }

    /**
     * Aplica cupão e saldo sobre um preço-base já calculado.
     *
     * Separado do `calculateTransaction` para o checkout da seleção de
     * profissional poder usar EXATAMENTE estas regras a partir do preço
     * congelado no candidato, em vez de recalcular — recalcular no checkout
     * daria outro número, porque a comissão horária muda com a hora do dia
     * (ver docs/matching.md).
     *
     * @param  \App\Models\User|null  $customer  null para convidado (sem saldo nem histórico de cupões)
     * @param  int|null  $travelAmount  parcela do `originalAmount` que é só deslocação, já com
     *                                  comissão e IVA aplicados (ver RateService::calculateForCustomerTravelPortion).
     *                                  Null quando não há para mostrar (ex.: checkout do matching, que parte de um
     *                                  preço já congelado no candidato sem essa parcela guardada) — nesse caso as
     *                                  chaves `travel_amount*` saem de fora da resposta, e não a zero, para o
     *                                  cliente nunca ler "deslocação: 0,00€" quando a informação simplesmente não
     *                                  existe.
     */
    protected function buildTransactionTotals(
        $customer,
        int $originalAmount,
        int $vendorAmount,
        float|int $distance,
        ?Voucher $voucher = null,
        bool $isGuest = false,
        ?int $travelAmount = null,
    ): array {
        $amount = $originalAmount;
        $discountAmount = 0;

        // Para cliente autenticado, respeitar também o limite de utilização por-cliente
        // (canBeUsedBy) — não só a validade. Guest não tem histórico de uso, logo só isValid().
        if ($voucher && $voucher->isValid() && ($isGuest || $voucher->canBeUsedBy($customer))) {
            $nominalDiscount = (int) round($originalAmount * ($voucher->discount_percentage / 100));

            // Voucher financiado pela plataforma: o desconto sai da comissão, mas o cliente
            // nunca pode pagar menos do que o valor do profissional (comissão com piso em 0).
            $maxDiscount = max(0, $originalAmount - $vendorAmount);
            $discountAmount = min($nominalDiscount, $maxDiscount);
            $amount = $originalAmount - $discountAmount;
        }

        if (! $isGuest) {
            $balance = $customer->balance_int;

            $balance_after_payment = ($customer->balance - $amount);
            if ($balance_after_payment < 0) {
                $balance_after_payment = 0;
            }
        } else {
            $balance = 0;
            $balance_after_payment = 0;
        }

        $valueForPayment = $amount - $balance;

        if ($valueForPayment <= 0) {
            $valueForPayment = 0;
        }

        $balance_total_used = $balance - $balance_after_payment;

        return [
            'amount' => $amount,
            'amount_formated' => number_format($amount / 100, 2, '.', ' '),
            'amount_for_vendor' => $vendorAmount,
            'amount_for_vendor_formated' => number_format($vendorAmount / 100, 2, '.', ' '),
            ...($travelAmount !== null ? [
                'travel_amount' => $travelAmount,
                'travel_amount_formated' => number_format($travelAmount / 100, 2, '.', ' '),
            ] : []),
            'distance' => $distance,
            'original_amount' => $originalAmount,
            'original_amount_formated' => number_format($originalAmount / 100, 2, '.', ' '),
            'discount_amount' => $discountAmount,
            'discount_amount_formated' => number_format($discountAmount / 100, 2, '.', ' '),
            'balance' => $balance,
            'balance_formated' => number_format($balance / 100, 2, '.', ' '),
            'value_for_payment' => $valueForPayment,
            'value_for_payment_formated' => number_format($valueForPayment / 100, 2, '.', ' '),
            'balance_after_payment' => $balance_after_payment,
            'balance_after_payment_formated' => number_format($balance_after_payment / 100, 2, '.', ' '),
            'balance_total_used' => $balance_total_used,
            'balance_total_used_formated' => number_format($balance_total_used / 100, 2, '.', ' '),
        ];
    }
}
