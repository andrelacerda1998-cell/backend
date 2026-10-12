<?php

namespace App\Services\Auditoria;

use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Resolver;

/**
 * Preenche `audits.staff_email` — ver StaffDoBackoffice e config/audit.php.
 *
 * Fora da API de admin (a app, o Filament, os comandos) fica null: só o
 * AdminApiToken guarda quem é, e só com o token certo.
 */
class EmailDoStaffResolver implements Resolver
{
    public static function resolve(Auditable $auditable): ?string
    {
        // Uma auditoria pré-carregada (para ir pela fila) já traz o valor.
        if (! empty($auditable->preloadedResolverData['staff_email'] ?? null)) {
            return $auditable->preloadedResolverData['staff_email'];
        }

        return StaffDoBackoffice::emailAtual();
    }
}
