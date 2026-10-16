<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetMyCrew;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta la asignación del día de un chofer: el vehículo y la tripulación que el admin armó para él en
 * una fecha. `date` es la fecha de armado en formato 'Y-m-d' (ya resuelta por el controller a la zona
 * America/La_Paz).
 */
final readonly class GetMyCrew implements Query
{
    public function __construct(
        public string $driverId,
        public string $date,
    ) {}
}
