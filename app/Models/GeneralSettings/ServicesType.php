<?php

namespace App\Models\GeneralSettings;

use App\Casts\TranslatableArrayCast;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;

class ServicesType extends Model implements Auditable, HasMedia
{
    use HasFactory, HasTranslations, InteractsWithMedia, \OwenIt\Auditing\Auditable, SoftDeletes;

    protected array $translatable = ['name'];

    protected $fillable = ['name', 'includes', 'excludes', 'suggested_price', 'time', 'starts_from', 'operation_area_id'];

    protected $casts = [
        'includes' => TranslatableArrayCast::class,
        'excludes' => TranslatableArrayCast::class,
    ];

    public function vendors()
    {
        return $this->belongsToMany(Vendor::class)->using(VendorServiceTypes::class)->whereNull('vendors.deleted_at')->wherePivot('services_type_vendor.deleted_at', null);
    }

    public function suggestedPrice(): Attribute
    {
        return Attribute::make(get: function ($value) {
            return number_format($value / 100, 2, '.');
        }, set: fn (string $value) => [
            'suggested_price' => $value * 100,
        ])->shouldCache();
    }

    public function OperationArea(): BelongsTo
    {
        return $this->belongsTo(OperationArea::class);
    }

    public function getTranslatedIncludes($locale = null): array
    {
        $locale = $locale ?? app()->getLocale();

        return TranslatableArrayCast::getTranslated($this->includes, $locale);
    }

    public function getTranslatedExcludes($locale = null): array
    {
        $locale = $locale ?? app()->getLocale();

        return TranslatableArrayCast::getTranslated($this->excludes, $locale);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('webp')
            ->format('webp')
            ->performOnCollections('image')
            ->queued();
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->hasMedia('image')) {
            return null;
        }

        $webpUrl = $this->getFirstTemporaryUrl(now()->addHour(), 'image', 'webp');

        return $webpUrl ?: $this->getFirstTemporaryUrl(now()->addHour(), 'image');
    }
}
