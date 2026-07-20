<?php

namespace App\Models\Vendor;

use App\Models\GeneralSettings\Document;
use App\Models\Vendor;
use Dom\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class VendorDocuments extends Model implements Auditable, HasMedia
{
    use InteractsWithMedia, \OwenIt\Auditing\Auditable;

    protected $fillable = ['vendor_id', 'status', 'reason', 'document_id', 'expiration_date'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
