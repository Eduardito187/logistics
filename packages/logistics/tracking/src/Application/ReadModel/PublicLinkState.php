<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

/**
 * Estado del enlace público por token, para que Presentation elija la respuesta correcta cuando las
 * consultas públicas no devuelven datos: 404 genérico (no existe) o "enlace vencido" (existió, ya expiró).
 */
enum PublicLinkState: string
{
    case Active = 'active';
    case Expired = 'expired';
    case NotFound = 'not_found';
}
