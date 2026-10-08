<?php

namespace App\Services\Carteira;

/** Um código de convite que não pode ser usado, com a razão pronta a mostrar. */
class ConviteRecusado extends \RuntimeException
{
    public function __construct(public readonly string $razao)
    {
        parent::__construct(__('convites.recusado.'.$razao));
    }
}
