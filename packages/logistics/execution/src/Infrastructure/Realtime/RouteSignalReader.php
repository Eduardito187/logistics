<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Realtime;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Resuelve, para la señal `route.updated`, QUÉ ruta cambió y A QUIÉN avisar. Solo lectura, por el query
 * builder y con consultas baratas por índice; corre en la cola, nunca en un request.
 *
 * Destino (ruta + camión + día), de lo más específico a lo más general:
 *   1. el hecho trae la ruta → esa ruta;
 *   2. trae el camión → la ruta de ese camión en el día del hecho (o en el día de hoy): la abierta antes
 *      que la cerrada; si el camión todavía no tiene ruta ese día, el destino va sin ruta;
 *   3. solo trae el envío → la ruta más reciente que lo tiene como parada; si no está en ninguna, el camión
 *      de su entrega.
 *
 * Destinatarios: los usuarios de la app vinculados (`users.driver_id`) al chofer o a los ayudantes de la
 * tripulación de ese camión en ese día. Se suman dos fuentes, porque el camión puede cambiar de manos
 * después de armada la ruta: la tripulación grabada en la ruta y las de `fleet_crews` del camión y la
 * fecha. Las tablas de flota y de usuarios se leen por valor (sin acoplar contextos).
 *
 * @phpstan-type RouteTarget array{route_id: string|null, vehicle_id: string, date: string, crew: list<string>}
 */
final class RouteSignalReader
{
    private const ROUTES = 'execution_driver_routes';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Estados de una ruta abierta, en orden de prioridad: la que ya salió manda sobre la planificada.
     *
     * @var list<string>
     */
    private const OPEN_STATUSES = ['IN_PROGRESS', 'PLANNED', 'SUSPENDED'];

    /** Tope de rutas de un camión en un día que se miran (muy por encima de lo operativo). */
    private const MAX_ROUTES_PER_DAY = 20;

    /** Columnas de la ruta que alcanzan para armar el destino. */
    private const ROUTE_COLUMNS = ['r.id', 'r.vehicle_id', 'r.route_date', 'r.status', 'r.driver_id', 'r.helper_ids'];

    /**
     * @param  list<string>      $vehicleIds
     * @return list<RouteTarget>
     */
    public function targets(?string $routeId, array $vehicleIds, ?string $shipmentId, ?string $date): array
    {
        $targets = $routeId !== null ? $this->byRoute($routeId) : [];

        if ($targets === []) {
            foreach ($vehicleIds as $vehicleId) {
                $targets[] = $this->byVehicle($vehicleId, $date);
            }
        }

        if ($targets === [] && $shipmentId !== null) {
            $targets = $this->byShipment($shipmentId);
        }

        return $targets;
    }

    /**
     * Ids (enteros) de los usuarios de la app que son tripulación del destino.
     *
     * @param  RouteTarget $target
     * @return list<int>
     */
    public function userIds(array $target): array
    {
        $members = self::uuids([...$target['crew'], ...$this->crewMembers($target['vehicle_id'], $target['date'])]);
        if ($members === []) {
            return [];
        }

        $ids = [];
        foreach (DB::table('users')->whereIn('driver_id', $members)->orderBy('id')->pluck('id') as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<RouteTarget>
     */
    private function byRoute(string $routeId): array
    {
        $row = DB::table(self::ROUTES.' as r')->where('r.id', $routeId)->first(self::ROUTE_COLUMNS);
        $target = $row instanceof stdClass ? self::target($row) : null;

        return $target === null ? [] : [$target];
    }

    /**
     * Ruta del camión en el día: la abierta si hay; si no, la última que se tocó; si no hay ninguna, el
     * destino va sin ruta (el camión igual tiene tripulación a la que avisar).
     *
     * @return RouteTarget
     */
    private function byVehicle(string $vehicleId, ?string $date): array
    {
        $day = $date ?? Carbon::now()->toDateString();

        $rows = DB::table(self::ROUTES.' as r')
            ->where('r.vehicle_id', $vehicleId)
            ->whereDate('r.route_date', $day)
            ->orderByDesc('r.updated_at')
            ->limit(self::MAX_ROUTES_PER_DAY)
            ->get(self::ROUTE_COLUMNS)
            ->all();

        return self::pick($rows) ?? ['route_id' => null, 'vehicle_id' => $vehicleId, 'date' => $day, 'crew' => []];
    }

    /**
     * De las rutas del día (la más reciente primero), la abierta de mayor prioridad; si no hay, la primera.
     *
     * @param  array<int, mixed> $rows
     * @return RouteTarget|null
     */
    private static function pick(array $rows): ?array
    {
        $chosen = null;
        foreach ([...self::OPEN_STATUSES, null] as $status) {
            foreach ($rows as $row) {
                if ($chosen === null && $row instanceof stdClass && ($status === null || ($row->status ?? null) === $status)) {
                    $chosen = self::target($row);
                }
            }
        }

        return $chosen;
    }

    /**
     * @return list<RouteTarget>
     */
    private function byShipment(string $shipmentId): array
    {
        $row = DB::table('execution_driver_route_stops as s')
            ->join(self::ROUTES.' as r', 'r.id', '=', 's.route_id')
            ->where('s.shipment_id', $shipmentId)
            ->orderByDesc('r.route_date')
            ->orderByDesc('r.updated_at')
            ->first(self::ROUTE_COLUMNS);

        $target = $row instanceof stdClass ? self::target($row) : null;
        if ($target !== null) {
            return [$target];
        }

        // Sin parada en ninguna ruta: el camión de su entrega, con la ruta que tenga hoy.
        $vehicleIds = self::uuids([
            DB::table('execution_deliveries')->where('shipment_id', $shipmentId)->value('vehicle_id'),
        ]);

        return $vehicleIds === [] ? [] : [$this->byVehicle($vehicleIds[0], null)];
    }

    /**
     * Chofer y ayudantes de todas las tripulaciones del camión en la fecha.
     *
     * @return list<mixed>
     */
    private function crewMembers(string $vehicleId, string $date): array
    {
        $members = [];

        $crews = DB::table('fleet_crews')
            ->where('vehicle_id', $vehicleId)
            ->whereDate('assembled_for', $date)
            ->get(['driver_id', 'helper_ids']);

        foreach ($crews as $crew) {
            $members = [...$members, $crew->driver_id ?? null, ...self::decodeIds($crew->helper_ids ?? null)];
        }

        return $members;
    }

    /**
     * @return RouteTarget|null
     */
    private static function target(stdClass $row): ?array
    {
        // Dos ids válidos y distintos (ruta y camión); con uno inválido la fila no sirve como destino.
        $ids = self::uuids([$row->id ?? null, $row->vehicle_id ?? null]);
        $routeDate = $row->route_date ?? null;
        $date = is_string($routeDate) ? substr($routeDate, 0, 10) : '';

        if (count($ids) !== 2 || $date === '') {
            return null;
        }

        return [
            'route_id' => $ids[0],
            'vehicle_id' => $ids[1],
            'date' => $date,
            'crew' => self::uuids([$row->driver_id ?? null, ...self::decodeIds($row->helper_ids ?? null)]),
        ];
    }

    /**
     * Lista de ids guardada como JSON (`helper_ids`): tolera el texto crudo o el arreglo ya decodificado.
     *
     * @return list<mixed>
     */
    private static function decodeIds(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Deja solo los UUIDs con forma válida (en minúscula y sin repetir), en el orden recibido: comparar otro
     * texto contra una columna `uuid` hace fallar la consulta en PostgreSQL.
     *
     * @param  array<int, mixed> $values
     * @return list<string>
     */
    private static function uuids(array $values): array
    {
        $uuids = [];
        foreach ($values as $value) {
            if (is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1) {
                $uuids[] = strtolower($value);
            }
        }

        return array_values(array_unique($uuids));
    }
}
