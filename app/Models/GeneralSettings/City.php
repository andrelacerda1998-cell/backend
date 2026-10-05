<?php

namespace App\Models\GeneralSettings;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class City extends Model
{
    protected $fillable = ['name', 'district', 'suggested', 'active', 'latitude', 'longitude', 'radius_km'];

    protected function casts(): array
    {
        return [
            'suggested' => 'boolean',
            'active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_km' => 'integer',
        ];
    }

    /** Tecnicos que aceitam trabalhar nesta cidade. */
    public function availableVendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendor_available_cities')->withTimestamps();
    }

    /** Tecnicos que puseram esta cidade no top 3 de maior interesse. */
    public function preferredVendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendor_preferred_cities')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * A morada está dentro desta cidade?
     *
     * Pelo centro e pelo raio quando há coordenadas; pelo nome quando ainda
     * não há (cidade por geocodificar) — melhor do que tratar a cidade como
     * vazia e deixar o técnico sem pedidos nenhuns.
     */
    public function contem(float $latitude, float $longitude, ?string $nomeDaMorada): bool
    {
        if ($this->latitude !== null && $this->longitude !== null) {
            return calculate_distance($this->latitude, $this->longitude, $latitude, $longitude) <= ($this->radius_km ?: 15);
        }

        return $nomeDaMorada !== null && self::normalizar($nomeDaMorada) === self::normalizar($this->name);
    }

    public static function normalizar(string $nome): string
    {
        $semAcentos = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) ?: $nome;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($semAcentos)));
    }
}
