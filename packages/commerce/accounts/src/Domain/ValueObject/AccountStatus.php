<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Estado comercial de una cuenta.
 *
 * - Active: puede comprar/operar con normalidad.
 * - Suspended: bloqueada comercialmente (se conserva su histórico); no es un borrado.
 */
enum AccountStatus: string
{
    use CaseInsensitiveEnum;

    case Active = 'active';
    case Suspended = 'suspended';
}
