<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetDriverHistory;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el historial operativo cuantificable de un chofer entre dos fechas (inclusive): qué camiones
 * manejó, con qué ayudantes, cuántas paradas atendió y cómo cerraron. Las fechas van en 'Y-m-d'.
 */
final readonly class GetDriverHistory implements Query
{
    public function __construct(
        public string $driverId,
        public string $from,
        public string $to,
    ) {}
}
