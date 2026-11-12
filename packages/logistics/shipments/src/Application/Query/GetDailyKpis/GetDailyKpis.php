<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\GetDailyKpis;

use Logistics\Shared\Application\Query\Query;

/**
 * KPIs operativos de un día (GET /app/kpis/daily).
 *
 * El ALCANCE lo resuelve la puerta HTTP según el rol (server-side, no se confía en la app): `driver`
 * (el chofer autenticado, ignora filtros), `node` (una tienda o un nodo pedido) o `fleet` (toda la flota,
 * opcionalmente acotada por vehículo/chofer). `$emptyScope` = el alcance no puede tener datos (p. ej. una
 * cuenta de chofer sin chofer vinculado): se responde en cero sin consultar.
 */
final readonly class GetDailyKpis implements Query
{
    public function __construct(
        public string $date,
        public string $scope = 'fleet',
        public ?string $nodeId = null,
        public ?string $vehicleId = null,
        public ?string $driverId = null,
        public bool $emptyScope = false,
    ) {}
}
