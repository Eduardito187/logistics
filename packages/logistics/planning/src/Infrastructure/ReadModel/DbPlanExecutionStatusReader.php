<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Planning\Application\Contract\PlanExecutionStatusReader;
use Throwable;

/**
 * Lee el estado de ejecución de la ruta del día (`execution_driver_routes`) por el query builder, en solo
 * lectura y en LOTE (una consulta para todos los slots), sin acoplar Planificación a Ejecución. Tolerante:
 * si la tabla no está (contexto extraído o sin migrar) responde vacío y la vista del plan queda sin estado.
 *
 * Mapea el estado interno de la ruta al enum estable para la UI:
 *   PLANNED → NOT_STARTED · IN_PROGRESS / SUSPENDED → IN_PROGRESS · COMPLETED → COMPLETED.
 * Hay una sola ruta por (vehículo, día) (índice único), así que cada slot resuelve a un único estado.
 */
final readonly class DbPlanExecutionStatusReader implements PlanExecutionStatusReader
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function statusesFor(array $slots): array
    {
        if ($slots === []) {
            return [];
        }

        $vehicleIds = array_values(array_unique(array_filter(array_column($slots, 'vehicle_id'))));
        $dates = array_values(array_unique(array_filter(array_column($slots, 'plan_date'))));
        if ($vehicleIds === [] || $dates === []) {
            return [];
        }

        try {
            $rows = $this->connections->connection()->table('execution_driver_routes')
                ->whereIn('vehicle_id', $vehicleIds)
                ->whereIn('route_date', $dates)
                ->get(['vehicle_id', 'route_date', 'status']);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $mapped = self::map((string) ($row->status ?? ''));
            if ($mapped === null) {
                continue;
            }
            $out[(string) $row->vehicle_id.'|'.substr((string) $row->route_date, 0, 10)] = $mapped;
        }

        return $out;
    }

    /**
     * Estado interno de la ruta → enum estable de la UI. Un estado desconocido no se expone (null).
     */
    private static function map(string $status): ?string
    {
        return match (strtoupper($status)) {
            'PLANNED' => 'NOT_STARTED',
            'IN_PROGRESS', 'SUSPENDED' => 'IN_PROGRESS',
            'COMPLETED' => 'COMPLETED',
            default => null,
        };
    }
}
