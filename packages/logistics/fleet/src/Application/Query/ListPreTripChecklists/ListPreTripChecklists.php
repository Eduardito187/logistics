<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListPreTripChecklists;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las inspecciones pre-salida (más recientes primero). Filtros opcionales: vehículo, resultado
 * (aprobadas) y rango de fecha de inspección (Y-m-d, inclusive). Con `limit` el handler pagina en SQL y
 * devuelve una Page (ítems + total); sin `limit`, la lista completa (compatibilidad).
 */
final readonly class ListPreTripChecklists implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?bool $onlyPassed = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $limit = null,
        public ?int $offset = null,
    ) {}
}
