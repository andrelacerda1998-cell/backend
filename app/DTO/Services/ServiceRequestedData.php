<?php

namespace App\DTO\Services;

use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;

readonly class ServiceRequestedData
{
    public function __construct(
        public int $id,
        public ?int $schedule_id,
        public float $amount,
        public float $distance,
        public ?float $amount_for_vendor,
        public string $status,
        public array $vendor,
        public array $customer,
        public ?array $address,
        /**
         * Morada completa (rua, número, código postal, cidade, ...). Campo NOVO:
         * `address` mantém-se inalterado para não partir contratos existentes.
         */
        public ?array $address_details,
        /** Observações escritas pelo cliente ao abrir o pedido. Campo NOVO. */
        public ?string $customer_notes,
        /** URL assinados e temporarios (60 min) das fotos que o cliente juntou ao pedido. */
        public array $customer_photos,
        public array $service_area,
        public array $service_type,
        public ?array $schedule,
        public ?string $date_label,
        public Carbon $updated_at,
        public Carbon $server_time,
        public int $created_timestamp,
        public int $updated_timestamp,
    ) {}

    /**
     * Itens sem tradução na língua atual chegam aqui como string vazia
     * (TranslatableArrayCast::getTranslated). Mandá-los para a app dava linhas
     * em branco na lista do que o serviço inclui.
     */
    private static function cleanList(array $items): array
    {
        return array_values(array_filter(
            array_map(static fn ($item) => is_string($item) ? trim($item) : '', $items),
            static fn (string $item) => $item !== '',
        ));
    }

    public static function fromArray(Service $service, User $user): self
    {
        return new self(
            id: $service->id,
            schedule_id: $service->schedule?->id,
            amount: $service->amount,
            distance: $service->distance,
            amount_for_vendor: $service->amount_for_vendor,
            status: $service->status->value,
            vendor: [
                'username' => $user->vendor->username,
                'user' => [
                    'name' => $user->vendor->user->name,
                ],
            ],
            customer: $service->customer ? [
                ...$service->customer->only(['id', 'name', 'address']),
                // Contacto do cliente só depois de aceite/confirmado (schedule não-pending).
                'phone' => ($service->schedule && ! $service->schedule->is_pending)
                    ? $service->customer->phone_number
                    : null,
            ] : [],
            // Moradas guardadas nem sempre têm city/state (há registos só com nome e
            // coordenadas) — o acesso direto rebentava a lista inteira com 500.
            address: ['name' => $service->address
                ? trim(($service->address['city'] ?? '').', '.ucfirst($service->address['state'] ?? ''), ', ') ?: ($service->address['name'] ?? null)
                : null],
            address_details: $service->formatVendorAddress(),
            customer_notes: $service->customer_notes,
            customer_photos: $service->customerPhotosPayload(),
            service_area: $service->serviceType->operationArea->only(['name']),
            // includes/excludes vão para a app do técnico pelo mesmo motivo por
            // que vão para a do cliente: é o que separa "o que combinei fazer"
            // de "o que o cliente vai pedir na hora" — e é aí que nascem as
            // discussões à porta de casa.
            service_type: [
                ...$service->serviceType->only(['id', 'time', 'name']),
                'includes' => self::cleanList($service->serviceType->getTranslatedIncludes()),
                'excludes' => self::cleanList($service->serviceType->getTranslatedExcludes()),
            ],
            // vendor_confirmed_at vai junto: é o que permite à app do técnico
            // mostrar "Confirmar presença" ou "Presença confirmada" sem ter de
            // perguntar por outro pedido.
            schedule: $service->schedule
                ? array_merge(
                    $service->schedule->only('scheduled_day', 'scheduled_time_start', 'scheduled_time_end'),
                    [
                        'vendor_confirmed_at' => $service->schedule->vendor_confirmed_at?->toIso8601String(),
                        // O técnico tem de saber que este cliente volta: uma
                        // marcação que se repete todas as semanas pesa de outra
                        // maneira na agenda do que uma avulsa.
                        'recurrence' => $service->schedule->recurrence?->value,
                        'is_recurring' => $service->schedule->recurrence !== null
                            || $service->schedule->recurrence_parent_id !== null,
                    ],
                )
                : null,
            date_label: $service->date_label,
            updated_at: $service->updated_at,
            server_time: now(),
            created_timestamp: $service->created_at->timestamp,
            updated_timestamp: $service->updated_at->timestamp,
        );
    }
}
