<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Contract;

use DateTimeImmutable;

/**
 * Puerto de lectura CROSS-CONTEXT (execution) de la custodia de un envío: qué vehículo lo lleva y entre qué
 * instantes estuvo a bordo (primer escaneo de carga → entrega/fallo). Alimenta las migas de pan por envío y
 * el puente telemetría → seguimiento por envío. Tolerante: sin datos devuelve null / lista vacía.
 *
 * @phpstan-type Custody array{shipment_id: string, vehicle_id: string, status: string, loaded_at: DateTimeImmutable|null, closed_at: DateTimeImmutable|null}
 */
interface ShipmentCustodyReader
{
    /**
     * @return Custody|null
     */
    public function custodyOf(string $shipmentId): ?array;

    /**
     * Envíos a bordo del vehículo ahora (cargados o en camino, sin entregar ni fallar).
     *
     * @return list<string>
     */
    public function onboardShipmentIds(string $vehicleId): array;
}
