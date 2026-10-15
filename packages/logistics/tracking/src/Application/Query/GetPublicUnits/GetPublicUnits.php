<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicUnits;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta el detalle PÚBLICO de bultos de un envío por su token de rastreo. Devuelve una lista de
 * PublicUnitView (sin PII) o null si el token no corresponde a ningún seguimiento.
 */
final readonly class GetPublicUnits implements Query
{
    public function __construct(
        public string $token,
    ) {}
}
