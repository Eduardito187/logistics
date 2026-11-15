<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Logistics\Planning\Application\Contract\TripCandidateReader;
use Logistics\Planning\Application\ReadModel\TripCandidateCriteria;
use Logistics\Planning\Domain\Contract\ReplanBacklogRepository;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Infrastructure\ReadModel\ShipmentSqlFilters;

/**
 * Lector SQL de los pedidos CANDIDATOS a un viaje. Solo lectura por query builder sobre Envíos, Red,
 * Ejecución y los planes propios; filtro, orden por cercanía y página se resuelven EN LA BASE (dos
 * sentencias: conteo y página), sin una consulta por pedido ni llamadas al ruteador.
 *
 * Qué es un candidato para el día X:
 *  1. PLANIFICABLE ese día, con la misma regla de fecha del auto-planificador: envío recibido con fecha
 *     prometida X (y, si X es hoy, también los sin fecha y los atrasados), o envío del backlog de
 *     replanificación pendiente para X en estado no terminal.
 *  2. SIN CAMIÓN NI PLAN: no está en ninguna parada de un plan de ese día o posterior, y (los recibidos) no
 *     tienen una entrega abierta con vehículo en Ejecución.
 *  3. DEL DEPARTAMENTO de la base del camión (regla geográfica: una ruta no cruza departamentos). Un envío
 *     que todavía no tiene departamento resuelto se muestra igual (no se esconde por un dato faltante).
 *  4. DENTRO DEL ALCANCE del usuario: el predicado es el de Envíos ({@see ShipmentSqlFilters::scope()}),
 *     no una copia; lo mismo la búsqueda por texto ({@see ShipmentSqlFilters::search()}).
 *
 * Cercanía: haversine en SQL contra el punto de referencia. La ubicación es la del envío y, si el camión
 * descarga en un nodo (recojo en tienda o traspaso) y el envío no tiene coordenadas, la del nodo destino.
 * Los envíos sin ubicación quedan al final.
 */
final readonly class DbTripCandidateReader implements TripCandidateReader
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Estado de envío recién recibido y aún sin planificar. */
    private const PENDING_STATUS = 'RECEIVED';

    /** Estados no terminales en los que un envío del backlog de replanificación puede volver a planificarse. */
    private const REPLANNABLE_STATUSES = ['RECEIVED', 'PLANNED', 'IN_EXECUTION'];

    /** Latitud y longitud efectivas del envío (propias o, si descarga en un nodo, las del nodo destino). */
    private const LATITUDE = 'COALESCE(shipments.lat, tc_n.lat)::double precision';

    private const LONGITUDE = 'COALESCE(shipments.lng, tc_n.lng)::double precision';

    public function __construct(
        private ConnectionInterface $connection,
        private ReplanBacklogRepository $backlog,
    ) {}

    public function candidates(TripCandidateCriteria $criteria): Page
    {
        $query = $this->baseQuery($criteria);

        /** @var list<array{shipment_id: string, distance_meters: float|null}> $items */
        $items = [];

        $total = (clone $query)->count();
        if ($total === 0) {
            return new Page($items, 0);
        }

        $query->select('shipments.id');
        if ($criteria->anchorLatitude !== null && $criteria->anchorLongitude !== null) {
            // Haversine en metros; least(1, …) protege al asin del ruido de punto flotante.
            $query->selectRaw(
                '2 * 6371000 * asin(sqrt(least(1.0, '
                .'power(sin(radians('.self::LATITUDE.' - ?::double precision) / 2), 2) + '
                .'cos(radians(?::double precision)) * cos(radians('.self::LATITUDE.')) * '
                .'power(sin(radians('.self::LONGITUDE.' - ?::double precision) / 2), 2)))) AS distance_meters',
                [$criteria->anchorLatitude, $criteria->anchorLatitude, $criteria->anchorLongitude],
            )->orderByRaw('distance_meters ASC NULLS LAST');
        } else {
            $query->selectRaw('NULL::double precision AS distance_meters');
        }

        $rows = $query
            ->orderBy('shipments.id')
            ->limit(max(1, $criteria->limit))
            ->offset(max(0, $criteria->offset))
            ->get();

        foreach ($rows as $row) {
            $data = (array) $row;
            if (!is_scalar($data['id'] ?? null)) {
                continue;
            }

            $distance = $data['distance_meters'] ?? null;
            $items[] = [
                'shipment_id' => strtolower((string) $data['id']),
                'distance_meters' => is_numeric($distance) ? (float) $distance : null,
            ];
        }

        return new Page($items, $total);
    }

    private function baseQuery(TripCandidateCriteria $criteria): Builder
    {
        $day = $criteria->date;
        $replanIds = self::uuids($this->backlog->pendingShipmentIdsFor(PlanDate::fromString($day)));
        $pickupTypes = DeliveryMode::pickupNodeTypes();
        $internalSources = OrderSource::internalTransferValues();

        // El nodo destino solo aporta su ubicación cuando el camión descarga ahí (tienda / punto de recojo
        // o traspaso interno); un reparto a domicilio nunca se ubica en un nodo.
        $query = $this->connection->table('shipments')
            ->leftJoin('network_nodes as tc_n', static function (JoinClause $join) use ($pickupTypes, $internalSources): void {
                $join->on('tc_n.id', '=', 'shipments.destination_node_id')
                    ->where(static function (Builder $unloadsAtNode) use ($pickupTypes, $internalSources): void {
                        $unloadsAtNode->whereIn('tc_n.type', $pickupTypes)
                            ->orWhereIn('shipments.source', $internalSources);
                    });
            });

        // (1) Planificable ese día.
        $query->where(static function (Builder $plannable) use ($day, $criteria, $replanIds): void {
            $plannable->where(static function (Builder $received) use ($day, $criteria): void {
                $received->where('shipments.status', self::PENDING_STATUS)
                    ->where(static function (Builder $byDate) use ($day, $criteria): void {
                        $byDate->where('shipments.delivery_date', $day);
                        if ($criteria->includeUndated) {
                            $byDate->orWhereNull('shipments.delivery_date')
                                ->orWhere('shipments.delivery_date', '<', $day);
                        }
                    })
                    // Sin camión: ninguna entrega abierta con vehículo en Ejecución.
                    ->whereNotExists(static function (Builder $delivery): void {
                        $delivery->selectRaw('1')
                            ->from('execution_deliveries as tc_d')
                            ->whereColumn('tc_d.shipment_id', 'shipments.id')
                            ->whereNotNull('tc_d.vehicle_id');
                    });
            });

            if ($replanIds !== []) {
                $plannable->orWhere(static function (Builder $replan) use ($replanIds): void {
                    $replan->whereIn('shipments.id', $replanIds)
                        ->whereIn('shipments.status', self::REPLANNABLE_STATUSES);
                });
            }
        });

        // (2) Sin plan: no figura en una parada de un plan de ese día o posterior.
        $query->whereNotExists(static function (Builder $planned) use ($day): void {
            $planned->selectRaw('1')
                ->from('planning_plan_stops as tc_ps')
                ->join('planning_plans as tc_p', 'tc_p.id', '=', 'tc_ps.plan_id')
                ->whereColumn('tc_ps.shipment_id', 'shipments.id')
                ->where('tc_p.plan_date', '>=', $day);
        });

        // (3) Del departamento de la base del camión (o sin departamento resuelto todavía).
        if ($criteria->department !== null) {
            $department = $criteria->department;
            $query->where(static function (Builder $sameDepartment) use ($department): void {
                $sameDepartment->where('shipments.destination_department', $department)
                    ->orWhereNull('shipments.destination_department');
            });
        }

        $excluded = self::uuids($criteria->excludedShipmentIds);
        if ($excluded !== []) {
            $query->whereNotIn('shipments.id', $excluded);
        }

        if ($criteria->search !== null) {
            ShipmentSqlFilters::search($query, $criteria->search);
        }

        // (4) Alcance por rol: la regla única de Envíos.
        if ($criteria->scope !== null) {
            ShipmentSqlFilters::scope($query, $criteria->scope);
        }

        return $query;
    }

    /**
     * Solo los valores con forma de UUID, en minúsculas y sin repetidos (nunca se compara otra cosa contra
     * una columna uuid: en PostgreSQL falla con 22P02).
     *
     * @param  list<string> $values
     * @return list<string>
     */
    private static function uuids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (preg_match(self::UUID_PATTERN, $value) === 1) {
                $ids[strtolower($value)] = true;
            }
        }

        return array_map(strval(...), array_keys($ids));
    }
}
