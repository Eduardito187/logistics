<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Service;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;

/**
 * Ubica la ruta del día de un camión para el inicio/cierre (y su lectura posterior), con la MISMA regla en
 * el comando y en la consulta, para que la respuesta muestre la ruta que se acaba de tocar:
 *
 *   1. si la app manda `route_date`, esa;
 *   2. si no, la del día de `occurred_at` (en la zona que trae el timestamp: es el día local del chofer);
 *   3. si no hay, la ruta abierta vigente del camión (Planned / InProgress).
 *
 * Por (2) un reintento del cierre (ruta ya Completed, ya no "abierta") sigue encontrando la ruta y responde
 * idempotente en vez de 404.
 */
final readonly class DriverRouteLocator
{
    public function __construct(
        private VehicleRouteRepository $routes,
    ) {}

    public function locate(string $vehicleId, ?string $routeDate, ?string $occurredAt): ?VehicleRoute
    {
        if ($routeDate !== null && trim($routeDate) !== '') {
            return $this->routes->findByVehicleAndDate($vehicleId, RouteDate::fromString(trim($routeDate)));
        }

        if ($occurredAt !== null && trim($occurredAt) !== '') {
            $day = (new DateTimeImmutable($occurredAt))->format('Y-m-d');
            $route = $this->routes->findByVehicleAndDate($vehicleId, RouteDate::fromString($day));
            if ($route !== null) {
                return $route;
            }
        }

        return $this->routes->findOpenByVehicleId($vehicleId);
    }
}
