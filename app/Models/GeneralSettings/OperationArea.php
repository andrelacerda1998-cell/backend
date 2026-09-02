<?php

namespace App\Models\GeneralSettings;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;

class OperationArea extends Model implements Auditable, HasMedia
{
    use HasFactory, HasTranslations, InteractsWithMedia, \OwenIt\Auditing\Auditable, SoftDeletes;

    protected $fillable = ['name', 'documents', 'sort_order', 'is_active'];

    protected array $translatable = ['name'];

    protected $casts = [
        'documents' => 'collection',
        'is_active' => 'boolean',
    ];

    /** Ordem definida no backoffice; alfabética como desempate. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function servicesType(): HasMany
    {
        return $this->hasMany(ServicesType::class);
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'operation_area_vendors');
    }

    public function certifications(): Attribute
    {
        return Attribute::make(get: function () {
            return Document::whereIn('id', $this->documents?->values()->toArray() ?? [])->get();
        })->shouldCache();
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

        $webpUrl = $this->getFirstTemporaryUrl(now()->addHours(2), 'image', 'webp');

        return $webpUrl ?: $this->getFirstTemporaryUrl(now()->addHours(2), 'image');
    }
}
