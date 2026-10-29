<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\ValueObject;

/**
 * Estado comercial de una cuenta.
 *
 * - Active: puede comprar/operar con normalidad.
 * - Suspended: bloqueada comercialmente (se conserva su histórico); no es un borrado.
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
