<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetCorridorProfitability;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la rentabilidad de la flota por corredor entre dos fechas (YYYY-MM-DD): margen = ingreso − costo.
 *
 * El ingreso es OPCIONAL (ROADMAP 3-06): hoy no hay facturación, así que el ingreso llega como entrada
 * configurable. `revenueByCorridor` es un mapa corredor → ingreso del período en centavos; cuando es null
 * el handler cae a la configuración (`logistics-fleetops.revenue`). No se inventan ingresos: si no hay
 * ingreso para un corredor, su margen queda null.
 */
final readonly class GetCorridorProfitability implements Query
{
    /**
     * @param array<string, int>|null $revenueByCorridor
     */
    public function __construct(
        public string $from,
        public string $to,
        public ?array $revenueByCorridor = null,
    ) {}
}
