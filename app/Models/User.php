<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Services\AddressType;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Auth\ImpersonationCode;
use App\Models\Auth\PhoneNumberValidationCode;
use App\Models\GeneralSettings\Gender;
use App\Models\Schedule\Schedule;
use App\Models\User\UserBillingInfo;
use App\Notifications\Auth\PasswordReset;
use App\Observers\UserObserver;
use App\Support\Locale;
use Bavix\Wallet\Interfaces\Customer;
use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Interfaces\WalletFloat;
use Bavix\Wallet\Traits\CanPay;
use Bavix\Wallet\Traits\HasWallet;
use Bavix\Wallet\Traits\HasWalletFloat;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as ContractCanResetPassword;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\Expo\ExpoPushToken;
use OwenIt\Auditing\Contracts\Auditable;
use RwInteractive\PayshopSdk\PayShopCustomer;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;
use Storage;
use Tymon\JWTAuth\Contracts\JWTSubject;

#[ObservedBy(UserObserver::class)]
class User extends Authenticatable implements Auditable, ContractCanResetPassword,Wallet, Customer, FilamentUser, HasAvatar, HasLocalePreference, HasMedia, JWTSubject, WalletFloat
{
    use CanPay, HasWallet, HasWalletFloat, PayShopCustomer;
    use CanResetPassword, HasFactory, HasRoles, InteractsWithMedia, Notifiable,
        \OwenIt\Auditing\Auditable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Attributes to exclude from the Audit.
     *
     * @var array
     */
    protected $auditExclude = [
        'password',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'password',
        'email_verified_at',
        'remember_token',
        'nif',
        'gender_id',
        'phone_number',
        'phone_number_verified_at',
        'date_birthday',
        'language',
        // 'avatar_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'media',
        'avatar_url',
    ];

    protected $appends = ['can_request_service', 'avatar', 'allowed_by_zone', 'is_phone_only', 'profile_completed'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_test' => 'boolean',
        ];
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'gender_id');
    }

    public function vendor(): HasOne
    {
        return $this->hasOne(Vendor::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'customer_id');
    }

    public function hasVerifiedPhoneNumber(): bool
    {
        return ! is_null($this->phone_number_verified_at);
    }

    public function canRequestService(): Attribute
    {
        return Attribute::make(get: function () {
            $hasMainAddress = $this->addresses()->where('main_address', true)->exists();
            $hasOpenService = $this->openServices()->exists();

            return $hasMainAddress && ! $hasOpenService;
        })->shouldCache();
    }

    /**
     * Translated reasons for each failing condition of canRequestService().
     * Must stay consistent with the checks in canRequestService().
     */
    public function cannotRequestServiceReasons(): Collection
    {
        $reasons = collect();

        if (! $this->addresses()->where('main_address', true)->exists()) {
            $reasons->push(__('backoffice/customer.infolist.eligibility.no_main_address'));
        }

        $openServices = $this->openServices()->pluck('id');
        if ($openServices->isNotEmpty()) {
            $reasons->push(__('backoffice/customer.infolist.eligibility.open_service', [
                'services' => '#'.$openServices->join(', #'),
            ]));
        }

        return $reasons;
    }

    public function isPhoneOnly(): bool
    {
        return is_null($this->email) && is_null($this->password);
    }

    public function getIsPhoneOnlyAttribute(): bool
    {
        return $this->isPhoneOnly();
    }

    public function profileCompleted(): Attribute
    {
        return Attribute::make(get: function () {
            if ($this->isPhoneOnly()) {
                return $this->hasVerifiedPhoneNumber() &&
                    $this->addresses()->where('main_address', true)->exists() &&
                    ! empty(trim($this->first_name ?? ''));
            }

            return $this->hasVerifiedEmail() &&
                $this->hasVerifiedPhoneNumber() &&
                $this->addresses()->where('main_address', true)->exists() &&
                ! empty(trim($this->first_name ?? ''));
        })->shouldCache();
    }

    public function openServices()
    {
        return $this->services()->whereIn('status', [
            ServiceStatus::PENDING,
            ServiceStatus::ACCEPTED,
            ServiceStatus::FINISHED,
            ServiceStatus::ARRIVED,
        ])
            ->whereDoesntHave('schedule')
            ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PENDING]);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url ? Storage::url("$this->avatar_url") : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole('admin', 'super-admin');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'customer_id');
    }

    public function billingInfo(): HasOne
    {
        return $this->hasOne(UserBillingInfo::class);
    }

    public function phoneNumberValidationCodes(): HasMany
    {
        return $this->hasMany(PhoneNumberValidationCode::class);
    }

    public function impersonationCodes(): HasMany
    {
        return $this->hasMany(ImpersonationCode::class);
    }

    public function setNameAttribute($string): void
    {
        $split = explode(' ', $string, 2);
        $this->first_name = $split[0] ?? '';
        $this->last_name = $split[1] ?? '';
    }

    public function getPublicDisplayName(): string
    {
        $firstName = trim($this->first_name ?? '');
        $lastName = trim($this->last_name ?? '');

        if ($firstName === '') {
            $phone = preg_replace('/\D/', '', $this->phone_number ?? '');

            return $phone !== '' ? '***'.mb_substr($phone, -3) : '';
        }

        if ($lastName === '') {
            return $firstName;
        }

        return $firstName.' '.mb_strtoupper(mb_substr($lastName, 0, 1)).'.';
    }

    public function isVendor(): bool
    {
        // Assuming 'vendor' is a relationship method defined in the User model
        return $this->vendor()->withTrashed()->exists();
    }

    /**
     * O utilizador quer receber push deste tipo de notificação de técnico?
     *
     * Só se aplica a técnicos: clientes (e utilizadores sem registo de vendor)
     * devolvem sempre true, para não silenciar notificações do lado do cliente.
     */
    public function wantsVendorNotification(string $preference): bool
    {
        $vendor = $this->relationLoaded('vendor') ? $this->getRelation('vendor') : $this->vendor;

        return $vendor?->shouldReceive($preference) ?? true;
    }

    public function isCustomer(): bool
    {
        // Assuming a user is a customer if they are not a vendor
        return ! $this->isVendor();
    }

    public function mainAddress()
    {
        return $this->addresses()->where('main_address', true)->first();
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function notificationOptOuts(): HasMany
    {
        return $this->hasMany(NotificationCampaignOptOut::class);
    }

    /**
     * @return Collection<int, ExpoPushToken>
     */
    public function routeNotificationForExpo(): Collection
    {
        return $this->devices->pluck('expo_token');
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new PasswordReset($token, $this->email, $this->isVendor()));
    }

    public function getFirstName()
    {
        return trim($this->first_name ?? '') ?: 'Cliente';
    }

    public function getLastName()
    {
        $last = trim($this->last_name ?? '');
        if ($last !== '') {
            return $last;
        }

        $digits = preg_replace('/\D/', '', $this->phone_number ?? '');

        return $digits !== '' ? substr($digits, -4) : 'Piquet';
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getPhoneNumber()
    {
        return str_replace('+351-', '', $this->phone_number);
    }

    /**
     * Toda a escrita de phone_number fica no formato canónico +351XXXXXXXXX,
     * venha de onde vier (API, Filament, seeders). Rows legadas não são tocadas.
     */
    protected function phoneNumber(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null
                ? null
                : \App\Services\Common\PhoneLoginSmsService::normalizePhoneNumber($value),
        );
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('small')
            ->width(64)
            ->height(64)
            ->sharpen(10)
            ->format('webp')
            ->queued();
    }

    public function getAvatarAttribute(): null|array|string
    {
        if (! $this->hasMedia('avatar')) {
            return null;
        }

        if (request()->hasHeader('X-Livewire')) {
            return $this->getFirstTemporaryUrl(now()->addHour(), 'avatar', 'small');
        }

        return [
            'small' => $this->getFirstTemporaryUrl(now()->addHour(), 'avatar', 'small') ?? '',
            'src' => $this->getFirstTemporaryUrl(now()->addHour(), 'avatar') ?? '',
        ];
    }

    public function preferredLocale(): string
    {
        // Normalizado: há utilizadores gravados com language='pt', para o qual não
        // existe catálogo — sem isto caíam em inglês. Ver App\Support\Locale.
        return Locale::normalize($this->language ?? app()->getLocale());
    }

    /**
     * Normaliza o idioma em qualquer leitura de $user->language.
     *
     * As ~24 notificações resolvem o locale via `$notifiable->language ?? ...`,
     * pelo que o valor cru ('pt', 'pt_BR', …) ganhava e as mensagens caíam em
     * inglês — o preferredLocale() sozinho não as cobre. Normalizar aqui, com a
     * mesma regra, corrige-as todas sem as editar. Ver App\Support\Locale.
     */
    public function language(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Locale::normalize($value),
        );
    }

    public function routeNotificationForTwilio(Notification $notification): string
    {
        $phoneNumber = preg_replace('/[^+\d]/', '', $this->phone_number);

        return $phoneNumber;
    }

    public function allowedByZone(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->addresses()
                ? (bool) optional(
                    $this->addresses()
                        ->where('address_type', AddressType::HOUSE_ADDRESS)
                        ->first()
                )->allowedZone
                : false
        )->shouldCache();
    }
}
