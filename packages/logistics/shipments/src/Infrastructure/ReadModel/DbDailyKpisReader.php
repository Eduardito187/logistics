<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Logistics\Shipments\Application\Contract\DailyKpisReader;
use Logistics\Shipments\Application\ReadModel\DailyKpisView;
use Throwable;

/**
 * Implementación SQL (PostgreSQL) de los KPIs operativos del día. Ver las definiciones en DailyKpisView.
 *
 * Lectura cruzada de SOLO LECTURA, por el query builder y sin acoplar dominios ajenos, de:
 *  - execution_driver_routes (día, vehículo, chofer) + execution_driver_route_stops (estado de la parada,
 *    cierre `settled_at` y, cuando exista, llegada `arrived_at`);
 *  - execution_deliveries (estado/POD de la entrega cuando la parada aún no se cerró; motivo de falla);
 *  - shipments (ventana prometida y nodo destino);
 *  - fleet_crews (chofer de la ruta cuando la ruta no lo tiene grabado);
 *  - fleetops_departures (odómetros de salida/regreso para los km).
 *
 * Costo fijo: TRES consultas agregadas (KPIs, motivos de falla, km), acotadas a las rutas de UN día. La
 * columna `arrived_at` de las paradas se lee de forma DEFENSIVA (Schema::hasColumn): mientras no exista,
 * `avg_stop_minutes` queda null. El resultado se cachea unos segundos en su forma primitiva.
 */
final readonly class DbDailyKpisReader implements DailyKpisReader
{
    /** Zona horaria operativa: el día y la ventana prometida son de Bolivia. */
    private const TIMEZONE = 'America/La_Paz';

    /** Cantidad de motivos de falla a devolver (los más frecuentes). */
    private const TOP_REASONS = 5;

    /** Hora de cierre de la parada: cierre de la parada o, si aún no se cerró, la del POD. */
    private const COMPLETED_AT = 'COALESCE(s.settled_at, d.pod_captured_at)';

    /** Parada entregada: cerrada como completada o, sin cerrar, con la entrega ya con POD. */
    private const DELIVERED = "(s.status = 'COMPLETED' OR (s.status = 'PENDING' AND COALESCE(d.status, '') = 'DELIVERED'))";

    /** Parada fallida: cerrada como fallida o, sin cerrar, con la entrega marcada fallida. */
    private const FAILED = "(s.status = 'FAILED' OR (s.status = 'PENDING' AND COALESCE(d.status, '') = 'FAILED'))";

    /** El envío tiene una ventana prometida medible (fecha + fin de ventana 'HH:MM'). */
    private const HAS_WINDOW = "(sh.delivery_date IS NOT NULL AND sh.delivery_window_end ~ '^[0-9]{2}:[0-9]{2}$')";

    /** El envío NO tuvo una parada en un día anterior (este es su primer intento). */
    private const FIRST_ATTEMPT = 'NOT EXISTS (SELECT 1 FROM execution_driver_route_stops s2 '
        .'JOIN execution_driver_routes r2 ON r2.id = s2.route_id '
        .'WHERE s2.shipment_id = s.shipment_id AND r2.route_date < r.route_date)';

    public function __construct(
        private ConnectionInterface $connection,
        private ShortReadCache $cache,
    ) {}

    public function daily(
        string $date,
        string $scope,
        ?string $nodeId = null,
        ?string $vehicleId = null,
        ?string $driverId = null,
    ): DailyKpisView {
        $key = compact('date', 'scope', 'nodeId', 'vehicleId', 'driverId');

        $data = $this->cache->remember('kpis-daily', $key, fn (): array => $this->compute($date, $scope, $nodeId, $vehicleId, $driverId)->toArray());

        return DailyKpisView::fromArray($data);
    }

    private function compute(string $date, string $scope, ?string $nodeId, ?string $vehicleId, ?string $driverId): DailyKpisView
    {
        $generatedAt = (new DateTimeImmutable('now'))->format(DATE_ATOM);
        $hasArrivedAt = $this->hasColumn('execution_driver_route_stops', 'arrived_at');

        try {
            $row = $this->stops($date, $nodeId, $vehicleId, $driverId)
                ->selectRaw($this->aggregateSelect($hasArrivedAt))
                ->first();
        } catch (Throwable) {
            return DailyKpisView::empty($date, $scope, $generatedAt);
        }

        /** @var \stdClass|null $row */
        $total = self::int($row->total ?? 0);
        $delivered = self::int($row->delivered ?? 0);
        $failed = self::int($row->failed ?? 0);
        $rescheduled = self::int($row->rescheduled ?? 0);
        $onTimeEligible = self::int($row->on_time_eligible ?? 0);
        $resolved = $delivered + $failed;

        return new DailyKpisView(
            date: $date,
            scope: $scope,
            deliveriesTotal: $total,
            delivered: $delivered,
            failed: $failed,
            rescheduled: $rescheduled,
            pending: max(0, $total - $delivered - $failed - $rescheduled),
            onTimeRate: $onTimeEligible > 0 ? round(self::int($row->on_time ?? 0) / $onTimeEligible, 4) : null,
            firstAttemptSuccessRate: $resolved > 0 ? round(self::int($row->first_attempt_ok ?? 0) / $resolved, 4) : null,
            avgStopMinutes: $hasArrivedAt && is_numeric($row->avg_stop_minutes ?? null) ? round((float) $row->avg_stop_minutes, 1) : null,
            km: $nodeId === null ? $this->km($date, $vehicleId, $driverId) : null,
            failedByReason: $failed > 0 ? $this->failedByReason($date, $nodeId, $vehicleId, $driverId) : [],
            generatedAt: $generatedAt,
        );
    }

    /**
     * Paradas de las rutas del día con los filtros de alcance aplicados (base de los agregados).
     */
    private function stops(string $date, ?string $nodeId, ?string $vehicleId, ?string $driverId): Builder
    {
        $query = $this->connection->table('execution_driver_route_stops as s')
            ->join('execution_driver_routes as r', 'r.id', '=', 's.route_id')
            ->leftJoin('shipments as sh', 'sh.id', '=', 's.shipment_id')
            ->leftJoin('execution_deliveries as d', 'd.shipment_id', '=', 's.shipment_id')
            ->where('r.route_date', $date);

        if ($vehicleId !== null) {
            $query->where('r.vehicle_id', $vehicleId);
        }
        if ($nodeId !== null) {
            $query->where('sh.destination_node_id', $nodeId);
        }
        if ($driverId !== null) {
            // Chofer de la ruta: el grabado en la ruta o, si la ruta no lo tiene, el del crew del vehículo
            // para ese día (misma regla que el historial operativo del chofer).
            $query->where(static function (Builder $where) use ($driverId): void {
                $where->where('r.driver_id', $driverId)
                    ->orWhere(static function (Builder $fallback) use ($driverId): void {
                        $fallback->whereNull('r.driver_id')->whereExists(static function (Builder $crew) use ($driverId): void {
                            $crew->selectRaw('1')
                                ->from('fleet_crews as c')
                                ->where('c.driver_id', $driverId)
                                ->whereColumn('c.vehicle_id', 'r.vehicle_id')
                                ->whereColumn('c.assembled_for', 'r.route_date');
                        });
                    });
            });
        }

        return $query;
    }

    private function aggregateSelect(bool $hasArrivedAt): string
    {
        $completedAt = self::COMPLETED_AT;
        $delivered = self::DELIVERED;
        $failed = self::FAILED;
        $hasWindow = self::HAS_WINDOW;
        $firstAttempt = self::FIRST_ATTEMPT;
        $zone = self::TIMEZONE;

        $select = 'COUNT(*) AS total, '
            ."SUM(CASE WHEN {$delivered} THEN 1 ELSE 0 END) AS delivered, "
            ."SUM(CASE WHEN {$failed} THEN 1 ELSE 0 END) AS failed, "
            ."SUM(CASE WHEN s.status = 'RESCHEDULED' THEN 1 ELSE 0 END) AS rescheduled, "
            ."SUM(CASE WHEN {$delivered} AND {$completedAt} IS NOT NULL AND {$hasWindow} THEN 1 ELSE 0 END) AS on_time_eligible, "
            // El CASE anidado garantiza que el CAST de la ventana solo se evalúa con un 'HH:MM' válido.
            ."SUM(CASE WHEN {$delivered} AND {$completedAt} IS NOT NULL AND {$hasWindow} THEN "
            ."CASE WHEN ({$completedAt} AT TIME ZONE '{$zone}') <= (sh.delivery_date + CAST(sh.delivery_window_end AS time)) THEN 1 ELSE 0 END "
            .'ELSE 0 END) AS on_time, '
            ."SUM(CASE WHEN {$delivered} AND {$firstAttempt} THEN 1 ELSE 0 END) AS first_attempt_ok";

        if ($hasArrivedAt) {
            $select .= ", AVG(CASE WHEN ({$delivered} OR {$failed}) AND s.arrived_at IS NOT NULL AND {$completedAt} >= s.arrived_at "
                ."THEN EXTRACT(EPOCH FROM ({$completedAt} - s.arrived_at)) / 60.0 END) AS avg_stop_minutes";
        }

        return $select;
    }

    /**
     * Motivos de las fallidas del día, de más a menos frecuente.
     *
     * @return list<array{reason: string, count: int}>
     */
    private function failedByReason(string $date, ?string $nodeId, ?string $vehicleId, ?string $driverId): array
    {
        $reason = "COALESCE(NULLIF(TRIM(d.failure_reason), ''), 'UNSPECIFIED')";

        try {
            $rows = $this->stops($date, $nodeId, $vehicleId, $driverId)
                ->whereRaw(self::FAILED)
                ->groupByRaw($reason)
                ->selectRaw("{$reason} AS reason, COUNT(*) AS total")
                ->orderByDesc('total')
                ->limit(self::TOP_REASONS)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            if (is_scalar($row->reason ?? null)) {
                $result[] = ['reason' => (string) $row->reason, 'count' => self::int($row->total ?? 0)];
            }
        }

        return $result;
    }

    /**
     * Km recorridos del día: suma de (odómetro de regreso − de salida) de las salidas con ambas lecturas
     * coherentes. Null si no hay ninguna lectura (o si la tabla no existe).
     */
    private function km(string $date, ?string $vehicleId, ?string $driverId): ?int
    {
        if (!$this->hasTable('fleetops_departures')) {
            return null;
        }

        try {
            $zone = new DateTimeZone(self::TIMEZONE);
            $from = new DateTimeImmutable($date.' 00:00:00', $zone);
            $to = $from->modify('+1 day');

            $query = $this->connection->table('fleetops_departures')
                ->where('departed_at', '>=', $from->format(DATE_ATOM))
                ->where('departed_at', '<', $to->format(DATE_ATOM))
                ->whereNotNull('odometer_out_km')
                ->whereNotNull('odometer_in_km')
                ->whereColumn('odometer_in_km', '>=', 'odometer_out_km');

            if ($vehicleId !== null) {
                $query->where('vehicle_id', $vehicleId);
            }
            if ($driverId !== null) {
                $query->where('driver_id', $driverId);
            }

            $row = $query->selectRaw('COUNT(*) AS readings, COALESCE(SUM(odometer_in_km - odometer_out_km), 0) AS km')->first();
        } catch (Throwable) {
            return null;
        }

        /** @var \stdClass|null $row */
        return self::int($row->readings ?? 0) > 0 ? self::int($row->km ?? 0) : null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return $this->connection instanceof Connection
                && $this->connection->getSchemaBuilder()->hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return $this->connection instanceof Connection
                && $this->connection->getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
