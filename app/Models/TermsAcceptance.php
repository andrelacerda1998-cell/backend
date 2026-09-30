<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prova de que um utilizador aceitou uma versão concreta de um documento.
 *
 * NÃO SE APAGA NEM SE ACTUALIZA. Cada aceitação é um facto com data; reescrever
 * uma destas linhas é apagar a prova de que ela existiu.
 */
class TermsAcceptance extends Model
{
    public const DOCUMENTO_PRESTADORES = 'provider_terms';

    protected $fillable = [
        'user_id', 'document', 'version', 'accepted_at',
        'content_digest', 'ip', 'user_agent',
    ];

    protected $casts = ['accepted_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
