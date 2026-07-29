<?php

namespace App\Models;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Observers\ServiceObserver;
use Bavix\Wallet\Interfaces\Customer;
use Bavix\Wallet\Interfaces\ProductLimitedInterface;
use Bavix\Wallet\Traits\HasWallet;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use OwenIt\Auditing\Contracts\Auditable;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Spatie\Geocoder\Exceptions\CouldNotGeocode;
use Spatie\Geocoder\Facades\Geocoder;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[ObservedBy(ServiceObserver::class)]
class Service extends Model implements Auditable, HasMedia, ProductLimitedInterface
{
    use HasWallet, InteractsWithMedia, \OwenIt\Auditing\Auditable, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'vendor_id',
        'status',
        'services_type_id',
        'address',
        'distance',
        'rating_by_customer',
        'rating_by_vendor',
        'customer_notes',
        'vendor_notes',
        'credit_used',
        'invoice_id',
        'pending_schedule_data',
        'voucher_id',
        'original_amount',
        'discount_amount',
        'is_test',
        'campaign_log_id',
    ];

    protected $hidden = [
        'rsa',
    ];

    /**
     * Attributes to exclude from the Audit.
     *
     * @var array
     */
    protected $auditExclude = [
        'rsa',
    ];

    protected $casts = [
        'status' => ServiceStatus::class,
        'payment_status' => PaymentStatus::class,
        'rsa' => 'encrypted',
        'address' => 'array',
        'pending_schedule_data' => 'array',
        'is_test' => 'boolean',
        'on_the_way_at' => 'datetime',
    ];

    protected $appends = ['price_rate'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')->whereDoesntHave('vendor');
    }

    public function customerUser(): BelongsTo
    {
        // Sem o filtro whereDoesntHave('vendor') e incluindo soft-deleted,
        // para o histórico do vendor sempre conseguir exibir o cliente (nome/avatar).
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServicesType::class, 'services_type_id');
    }

    public function paymentOrder()
    {
        return $this->belongsTo(PaymentOrder::class);
    }

    public function extras(): HasMany
    {
        return $this->hasMany(ServiceExtra::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(Schedule::class, 'service_id', 'id');
    }

    public function campaignLog(): BelongsTo
    {
        return $this->belongsTo(NotificationCampaignLog::class, 'campaign_log_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function voucherUsage(): HasOne
    {
        return $this->hasOne(VoucherUsage::class);
    }

    public function getAmountProduct(Customer $customer): int|string
    {
        return $this->credit_used;
    }

    public function scopePendingPaid(EloquentBuilder $query): EloquentBuilder
    {
        return $query->whereIn('status', [ServiceStatus::PENDING])->where('payment_status', PaymentStatus::PAID);
    }

    public function scopeServiceList(EloquentBuilder $query): EloquentBuilder
    {
        return $query->whereIn('status', [ServiceStatus::PENDING])->where('payment_status', PaymentStatus::PAID);
    }

    public function getMetaProduct(): ?array
    {
        return [
            'type' => 'internal/services.transactions_type.service',
            'description' => __('internal/services.accepted.description', [
                'service_name' => $this->serviceType->name,
                'date' => $this->created_at->format('Ymd-his'),
            ]),
            'admin_description' => __('internal/services.accepted.admin_description'),
            'class' => Service::class,
            'id' => $this->id,
        ];
    }

    public function canBuy(Customer $customer, int $quantity = 1, bool $force = false): bool
    {
        return true;
    }

    public function priceRate(): Attribute
    {
        return Attribute::make(get: function ($value) {
            return number_format($value / 100, 2, '.');
        }, set: fn (string $value) => [
            'price_rate' => $value * 100,
        ])->shouldCache();
    }

    /**
     * Get the VAT rate multiplier
     */
    private function getVatRate(): float
    {
        $vat = config('services.invoiceExpress.vat', 23);

        return 1 + ($vat / 100);
    }

    /**
     * Get amount without VAT
     */
    public function getAmountWithoutVatAttribute(): ?int
    {
        if (! $this->amount) {
            return null;
        }

        return (int) round($this->amount / $this->getVatRate());
    }

    /**
     * Get amount for vendor without VAT
     */
    public function getAmountForVendorWithoutVatAttribute(): ?int
    {
        if (! $this->amount_for_vendor) {
            return null;
        }

        return (int) round($this->amount_for_vendor / $this->getVatRate());
    }

    /**
     * Get commission with VAT (difference between customer amount and vendor amount, both with VAT)
     */
    public function getCommissionWithVatAttribute(): ?int
    {
        if ($this->amount === null || $this->amount_for_vendor === null) {
            return null;
        }

        return $this->amount - $this->amount_for_vendor;
    }

    /**
     * Get commission without VAT (difference between customer amount and vendor amount, both without VAT)
     */
    public function getCommissionWithoutVatAttribute(): ?int
    {
        $amountWithoutVat = $this->amount_without_vat;
        $amountForVendorWithoutVat = $this->amount_for_vendor_without_vat;

        if ($amountWithoutVat === null || $amountForVendorWithoutVat === null) {
            return null;
        }

        return $amountWithoutVat - $amountForVendorWithoutVat;
    }

    public function formatDataForVendor(): array
    {
        $service = $this;
        $language = app()->getLocale();
        $address = $service->formatVendorAddress();

        return [
            'id' => $service->id,
            'status' => $service->status,
            'on_the_way_at' => $service->on_the_way_at?->toIso8601String(),
            'is_immediate' => ! $service->schedule()->exists(),
            'scheduled_at' => $service->schedule?->scheduled_day,
            'created_at' => $service->created_at?->toIso8601String(),
            'distance' => $service->distance,
            'customer_notes' => $service->customer_notes,
            'vendor_notes' => $service->vendor_notes,
            'amount' => $service->amount_for_vendor,
            'customer' => $service->customer->only('name', 'phone_number', 'email', 'avatar'),
            'vendor' => $service->vendor->user ? [
                'user' => $service->vendor->user->only('name', 'phone_number', 'email', 'avatar'),
                'price_rate' => $service->vendor->price_rate,
                'location' => $service->vendor?->currentLocation?->only('latitude', 'longitude'),
            ] : null,
            'service_type' => $service->serviceType ? [
                'id' => $service->serviceType->id,
                'name' => $service->serviceType->getTranslation('name', $language),
                'time' => $service->serviceType->time,
                'operation_area' => [
                    'id' => $service->serviceType->operationArea->id,
                    'name' => $service->serviceType->operationArea->getTranslation('name', $language),
                ],
            ] : null,
            'address' => $address,
            'updated_at' => $service->updated_at,
            'rating_by_vendor' => $service->rating_by_vendor,
            'server_time' => now()->toIso8601String(),
            'created_timestamp' => $service->created_at->timestamp,
            'updated_timestamp' => $service->updated_at->timestamp,
        ];
    }

    public function formatVendorAddress(): ?array
    {
        if (! is_array($this->address)) {
            return null;
        }

        $fallbackAddress = $this->resolveVendorAddressFallback($this->address);
        $address = [
            ...$fallbackAddress,
            ...array_filter($this->address, fn (mixed $value): bool => $value !== null && $value !== ''),
        ];

        return [
            'name' => $address['name'] ?? $address['address_name'] ?? null,
            'street_name' => $address['street_name'] ?? null,
            'street_number' => $address['street_number'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'country' => $address['country'] ?? null,
            'additional_info' => $address['additional_info'] ?? null,
            'latitude' => $address['latitude'] ?? null,
            'longitude' => $address['longitude'] ?? null,
        ];
    }

    private function resolveVendorAddressFallback(array $address): array
    {
        $hasAddressDetails = filled($address['name'] ?? null)
            && filled($address['street_name'] ?? null)
            && filled($address['postal_code'] ?? null)
            && filled($address['city'] ?? null);

        if ($hasAddressDetails) {
            return [];
        }

        $latitude = $address['latitude'] ?? null;
        $longitude = $address['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [];
        }

        return Cache::remember(
            'service-vendor-address-'.$latitude.'-'.$longitude,
            now()->addDay(),
            function () use ($latitude, $longitude): array {
                try {
                    $geoAddress = Geocoder::setLanguage('pt')->getAddressForCoordinates((float) $latitude, (float) $longitude);

                    return $this->transformGeocodedAddress($geoAddress);
                } catch (CouldNotGeocode $exception) {
                    $message = $exception->getMessage();

                    if (str_contains($message, 'You must enable Billing on the Google Cloud')) {
                        $users = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'dev']))->get();

                        Notification::make()->title('Geocoding error')->body($message)->sendToDatabase($users);
                    }

                    return [];
                } catch (\Throwable $exception) {
                    return [];
                }
            }
        );
    }

    private function transformGeocodedAddress(array $geoAddress): array
    {
        $addressComponents = collect($geoAddress['address_components'] ?? []);

        return [
            'address_name' => $geoAddress['formatted_address'] ?? null,
            'name' => $geoAddress['formatted_address'] ?? null,
            'state' => $addressComponents->firstWhere('types.0', 'administrative_area_level_1')?->long_name,
            'city' => $addressComponents->firstWhere('types.0', 'administrative_area_level_2')?->long_name,
            'country' => $addressComponents->firstWhere('types.0', 'country')?->long_name,
            'street_number' => $addressComponents->firstWhere('types.0', 'street_number')?->long_name,
            'street_name' => $addressComponents->firstWhere('types.0', 'route')?->long_name,
            'postal_code' => $addressComponents->firstWhere('types.0', 'postal_code')?->long_name,
            'latitude' => $geoAddress['lat'] ?? null,
            'longitude' => $geoAddress['lng'] ?? null,
        ];
    }

    public function formatDataForCustomer(): array
    {
        $service = $this;
        $language = app()->getLocale();

        return [
            'id' => $service->id,
            'status' => $service->status,
            'distance' => $service->distance,
            'customer_notes' => $service->customer_notes,
            'vendor_notes' => $service->vendor_notes,
            'amount' => $service->amount,
            'customer' => $service->customer->only('name', 'phone_number', 'email', 'avatar'),
            'vendor' => $service?->vendor?->user ? [
                'user' => $service->vendor->user->only('name', 'phone_number', 'email', 'avatar'),
                'price_rate' => $service->vendor->price_rate,
                'location' => $service->vendor?->currentLocation?->only('latitude', 'longitude'),
            ] : null,
            'service_type' => $service->serviceType ? [
                'id' => $service->serviceType->id,
                'name' => $service->serviceType->getTranslation('name', $language),
                'time' => $service->serviceType->time,
                'operation_area' => [
                    'id' => $service->serviceType->operationArea->id,
                    'name' => $service->serviceType->operationArea->getTranslation('name', $language),
                ],
            ] : null,
            'address' => $service->address ? [
                'name' => $service->address['name'],
                'additional_info' => $service->address['additional_info'],
                'latitude' => $service->address['latitude'],
                'longitude' => $service->address['longitude'],
            ] : null,
            'updated_at' => $service->updated_at,
            'rating_by_customer' => $service->rating_by_customer,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
