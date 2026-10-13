<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicTracking;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta la página pública de rastreo por su token. Devuelve un PublicTrackingView (sin PII) o null
 * si el token no corresponde a ningún seguimiento.
 */
final readonly class GetPublicTracking implements Query
{
    public function __construct(
        public string $token,
    ) {}
}
