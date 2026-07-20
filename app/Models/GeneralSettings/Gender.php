<?php

namespace App\Models\GeneralSettings;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Translatable\HasTranslations;

class Gender extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, HasTranslations;

    protected $fillable = ['name'];

    public array $translatable = ['name'];
}
