<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Contract;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;

/**
 * Puerto de lectura CROSS-CONTEXT (planning / execution / shipments) de la ruta del día de un vehículo:
 * geometría planificada (para el desvío) y paradas restantes con coordenadas (para la ETA dinámica).
 * Solo lectura y tolerante: sin plan/ruta devuelve listas vacías.
 *
 * @phpstan-type RemainingStop array{shipment_id: string, sequence: int, lat: float|null, lng: float|null, planned_eta: string|null, order_reference: string|null, customer_name: string|null}
 */
interface ActiveRouteReader
{
    /**
     * Geometría (polilínea) del plan PUBLICADO del vehículo para el día, o lista vacía si no hay.
     *
     * @return list<GeoPoint>
     */
    public function plannedGeometry(string $vehicleId, DateTimeImmutable $day): array;

    /**
     * Paradas pendientes de la ruta del día del vehículo, en orden de visita.
     *
     * @return list<RemainingStop>
     */
    public function remainingStops(string $vehicleId, DateTimeImmutable $day): array;

    /**
     * Vehículos con ruta del día abierta (planificada o en curso): universo del recálculo de ETAs.
     *
     * @return list<string>
     */
    public function vehiclesWithOpenRoutes(DateTimeImmutable $day): array;
}
