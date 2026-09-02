<?php

namespace App\Models\GeneralSettings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Translatable\HasTranslations;

class Document extends Model implements Auditable
{
    use HasTranslations, \OwenIt\Auditing\Auditable, SoftDeletes;

    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'name', 'description', 'required',
    ];
}
