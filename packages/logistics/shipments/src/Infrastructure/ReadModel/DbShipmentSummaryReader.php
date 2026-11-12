<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Logistics\Shipments\Application\Contract\ShipmentSummaryReader;
use Logistics\Shipments\Application\ReadModel\ShipmentSummaryView;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Throwable;

/**
 * Implementación SQL del resumen de envíos (tablero del supervisor) y del conteo por estado.
 *
 * Solo agregados: GROUP BY status (índices `status` y `(delivery_date, status)`), COUNT de bultos por join
 * a `shipment_units` (índice `shipment_id`), GROUP BY del estado de la entrega en ejecución (índice único
 * `shipment_id`), y COUNT de planes publicados (`plan_date`) y alertas sin reconocer (`occurred_at`) del
 * período. Nunca se materializan filas.
 *
 * Las tablas de otros contextos (execution, planning, telemetry) se leen en SOLO LECTURA por el query
 * builder, cada fuente aislada en su try/catch: si una no está disponible, su campo queda en 0 / vacío.
 * El resultado se cachea unos segundos en su forma primitiva (ShortReadCache).
 */
final readonly class DbShipmentSummaryReader implements ShipmentSummaryReader
{
    /** Zona horaria operativa: el "día" del tablero es el día calendario en Bolivia. */
    private const TIMEZONE = 'America/La_Paz';

    public function __construct(
        private ConnectionInterface $connection,
        private ShortReadCache $cache,
    ) {}

    public function summary(
        ?string $deliveryDateFrom,
        ?string $deliveryDateTo,
        ?string $destinationNodeId,
        ?string $deliveryMode,
        string $periodFrom,
        string $periodTo,
    ): ShipmentSummaryView {
        $key = compact('deliveryDateFrom', 'deliveryDateTo', 'destinationNodeId', 'deliveryMode', 'periodFrom', 'periodTo');

        $data = $this->cache->remember('summary', $key, function () use ($deliveryDateFrom, $deliveryDateTo, $destinationNodeId, $deliveryMode, $periodFrom, $periodTo): array {
            $filters = static function (Builder $query) use ($deliveryDateFrom, $deliveryDateTo, $destinationNodeId, $deliveryMode): void {
                self::applyShipmentFilters($query, 'sh.', null, $destinationNodeId, $deliveryDateFrom, $deliveryDateTo, $deliveryMode);
            };

            return (new ShipmentSummaryView(
                $deliveryDateFrom,
                $deliveryDateTo,
                $this->statusCounts($filters),
                $this->executionStatusCounts($filters),
                $this->activePlans($periodFrom, $periodTo),
                $this->openAlerts($periodFrom, $periodTo),
                $this->unitsTotal($filters),
                (new DateTimeImmutable('now'))->format(DATE_ATOM),
            ))->toArray();
        });

        return ShipmentSummaryView::fromArray($data);
    }

    public function countByStatus(
        ?string $source,
        ?string $destinationNodeId,
        ?string $deliveryDateFrom,
        ?string $deliveryDateTo,
        ?string $deliveryMode,
    ): array {
        $key = compact('source', 'destinationNodeId', 'deliveryDateFrom', 'deliveryDateTo', 'deliveryMode');

        $data = $this->cache->remember('count-by-status', $key, fn (): array => $this->statusCounts(
            static function (Builder $query) use ($source, $destinationNodeId, $deliveryDateFrom, $deliveryDateTo, $deliveryMode): void {
                self::applyShipmentFilters($query, 'sh.', $source, $destinationNodeId, $deliveryDateFrom, $deliveryDateTo, $deliveryMode);
            },
        ));

        $counts = [];
        foreach ($data as $status => $count) {
            $counts[(string) $status] = is_numeric($count) ? (int) $count : 0;
        }

        return $counts;
    }

    /**
     * Filtros de envíos compartidos por todos los agregados (mismos predicados que el listado).
     */
    private static function applyShipmentFilters(
        Builder $query,
        string $prefix,
        ?string $source,
        ?string $destinationNodeId,
        ?string $deliveryDateFrom,
        ?string $deliveryDateTo,
        ?string $deliveryMode,
    ): void {
        if ($source !== null) {
            $query->where($prefix.'source', $source);
        }
        if ($destinationNodeId !== null) {
            $query->where($prefix.'destination_node_id', $destinationNodeId);
        }
        if ($deliveryDateFrom !== null) {
            $query->where($prefix.'delivery_date', '>=', $deliveryDateFrom);
        }
        if ($deliveryDateTo !== null) {
            $query->where($prefix.'delivery_date', '<=', $deliveryDateTo);
        }
        if ($deliveryMode !== null) {
            ShipmentSqlFilters::deliveryMode($query, $deliveryMode, $prefix.'destination_node_id', $prefix.'source');
        }
    }

    /**
     * GROUP BY status de los envíos filtrados, con TODOS los estados presentes (0 si no hay).
     *
     * @param  callable(Builder): void $filters
     * @return array<string, int>
     */
    private function statusCounts(callable $filters): array
    {
        $counts = [];
        foreach (ShipmentStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $query = $this->connection->table('shipments as sh');
        $filters($query);

        foreach ($query->groupBy('sh.status')->selectRaw('sh.status AS status, COUNT(*) AS total')->get() as $row) {
            /** @var \stdClass $row */
            if (is_scalar($row->status ?? null)) {
                $counts[(string) $row->status] = is_numeric($row->total ?? null) ? (int) $row->total : 0;
            }
        }

        return $counts;
    }

    /**
     * GROUP BY del estado de la entrega en ejecución de los envíos filtrados (solo estados presentes).
     *
     * @param  callable(Builder): void $filters
     * @return array<string, int>
     */
    private function executionStatusCounts(callable $filters): array
    {
        try {
            $query = $this->connection->table('execution_deliveries as d')
                ->join('shipments as sh', 'sh.id', '=', 'd.shipment_id');
            $filters($query);

            $rows = $query->groupBy('d.status')->selectRaw('d.status AS status, COUNT(*) AS total')->get();
        } catch (Throwable) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            if (is_scalar($row->status ?? null)) {
                $counts[(string) $row->status] = is_numeric($row->total ?? null) ? (int) $row->total : 0;
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Bultos físicos (shipment_units) de los envíos filtrados.
     *
     * @param callable(Builder): void $filters
     */
    private function unitsTotal(callable $filters): int
    {
        try {
            $query = $this->connection->table('shipment_units as u')
                ->join('shipments as sh', 'sh.id', '=', 'u.shipment_id');
            $filters($query);

            return $query->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Planes PUBLICADOS cuya fecha cae en el período. */
    private function activePlans(string $periodFrom, string $periodTo): int
    {
        try {
            return $this->connection->table('planning_plans')
                ->where('status', 'PUBLISHED')
                ->whereBetween('plan_date', [$periodFrom, $periodTo])
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Alertas de telemetría SIN reconocer ocurridas en el período (días calendario de La Paz). */
    private function openAlerts(string $periodFrom, string $periodTo): int
    {
        try {
            $zone = new DateTimeZone(self::TIMEZONE);
            $from = new DateTimeImmutable($periodFrom.' 00:00:00', $zone);
            $to = (new DateTimeImmutable($periodTo.' 00:00:00', $zone))->modify('+1 day');

            return $this->connection->table('telemetry_alerts')
                ->whereNull('acknowledged_at')
                ->where('occurred_at', '>=', $from->format(DATE_ATOM))
                ->where('occurred_at', '<', $to->format(DATE_ATOM))
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
