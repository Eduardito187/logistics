<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Estado del enlace público por token, para que Presentation elija la respuesta correcta cuando las
 * consultas públicas no devuelven datos: 404 genérico (no existe) o "enlace vencido" (existió, ya expiró).
 */
enum PublicLinkState: string
{
    use CaseInsensitiveEnum;

    case Active = 'active';
    case Expired = 'expired';
    case NotFound = 'not_found';
}
