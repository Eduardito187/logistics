<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Application\Contract\PlannedRouteStopsProvider;
use Throwable;

/**
 * Lee las paradas del plan PUBLICADO (contexto planning) por el query builder, en solo lectura, para
 * armar la ruta del día. No usa los modelos Eloquent de planning: toma las columnas directamente, de modo
 * que Ejecución no se acopla al dominio del otro contexto (mismo patrón que StopEnrichmentReader).
 *
 * El plan es único por (vehículo, fecha); solo se toma si está PUBLISHED (lo que el humano confirmó y
 * quedó disponible para el chofer). Tolerante: si las tablas no existen, devuelve lista vacía.
 */
final readonly class DbPlannedRouteStopsProvider implements PlannedRouteStopsProvider
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forVehicleOnDate(string $vehicleId, string $routeDate): array
    {
        try {
            $plan = $this->connection->table('planning_plans')
                ->where('vehicle_id', $vehicleId)
                ->where('plan_date', $routeDate)
                ->where('status', 'PUBLISHED')
                ->first(['id']);

            if ($plan === null || !isset($plan->id)) {
                return [];
            }

            $rows = $this->connection->table('planning_plan_stops')
                ->where('plan_id', (string) $plan->id)
                ->orderBy('sequence')
                ->get(['shipment_id', 'sequence', 'eta', 'order_reference', 'customer_name', 'address']);
        } catch (Throwable) {
            return [];
        }

        $stops = [];
        foreach ($rows as $row) {
            $stops[] = new RouteStopInput(
                shipmentId: (string) $row->shipment_id,
                sequence: (int) $row->sequence,
                orderReference: self::str($row->order_reference ?? null),
                customerName: self::str($row->customer_name ?? null),
                address: self::str($row->address ?? null),
                etaAt: self::str($row->eta ?? null),
                lat: null,
                lng: null,
            );
        }

        return $stops;
    }

    /** Normaliza un valor de la fila a string no vacío o null. */
    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
