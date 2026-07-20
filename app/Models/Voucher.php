<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class Voucher extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'max_uses',
        'discount_percentage',
        'valid_services',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'valid_services' => 'array',
        'is_active' => 'boolean',
    ];

    public function usages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Check if voucher is currently valid
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();

        // start_date tem de ser respeitado mesmo quando end_date é null. Antes, o ramo
        // "!end_date => return true" saltava a verificação de início e permitia usar o
        // voucher antes da data de arranque.
        if ($this->start_date && $now->lessThan($this->start_date)) {
            return false;
        }

        if (!$this->end_date) {
            return true;
        }

        return $now->lessThanOrEqualTo($this->end_date);
    }

    /**
     * Check if user can use this voucher
     */
    public function canBeUsedBy(User $user): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if (!$this->max_uses) {
            return true;
        }

        $usageCount = $this->usages()
            ->where('user_id', $user->id)
            ->count();

        return $usageCount < $this->max_uses;
    }

    /**
     * Check if voucher is valid for service type (scheduled or immediate)
     */
    public function isValidForServiceType(bool $isScheduled): bool
    {
        $validServices = $this->valid_services ?? [];
        
        if ($isScheduled) {
            return in_array('scheduled', $validServices);
        }

        return in_array('immediate', $validServices);
    }
}

