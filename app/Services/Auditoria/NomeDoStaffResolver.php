<?php

namespace App\Services\Auditoria;

use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Resolver;

/** Preenche `audits.staff_nome` — ver EmailDoStaffResolver. */
class NomeDoStaffResolver implements Resolver
{
    public static function resolve(Auditable $auditable): ?string
    {
        if (! empty($auditable->preloadedResolverData['staff_nome'] ?? null)) {
            return $auditable->preloadedResolverData['staff_nome'];
        }

        return StaffDoBackoffice::nomeAtual();
    }
}
