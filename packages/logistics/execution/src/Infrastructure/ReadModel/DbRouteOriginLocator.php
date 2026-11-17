<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\RouteOriginLocator;
use Throwable;

/**
 * Resuelve el punto de salida del vehículo leyendo su sede en Flota/Red (read-only, query builder, patrón
 * api-readmodel-enrichment): `fleet_vehicles.home_base_node_id` → `network_nodes` para tomar sus coordenadas.
 * Para un vehículo de transportadora, su sede (home base) es su carrier_hub, así que su ruta arranca en el
 * almacén central de la transportadora. Sin modelos ni dependencia composer de esos contextos.
 *
 * Tolerante: lo que no se resuelve vuelve null (la ruta cae al comportamiento actual, empezando en la primera
 * parada). El id del vehículo se valida como UUID antes de consultar para no gatillar el 22P02 de Postgres al
 * comparar un texto no-UUID contra una columna uuid.
 */
final readonly class DbRouteOriginLocator implements RouteOriginLocator
{
    /** Forma de un UUID (cualquier versión): guarda contra el 22P02 de Postgres al comparar con columna uuid. */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function originFor(string $vehicleId): ?array
    {
        $vehicleId = trim($vehicleId);
        if (preg_match(self::UUID_PATTERN, $vehicleId) !== 1) {
            return null;
        }

        try {
            $row = $this->connection->table('fleet_vehicles as v')
                ->join('network_nodes as n', 'n.id', '=', 'v.home_base_node_id')
                ->where('v.id', $vehicleId)
                ->first(['n.lat as lat', 'n.lng as lng']);
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $data = (array) $row;
        $lat = $data['lat'] ?? null;
        $lng = $data['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        // Mismo orden que las paradas de la ruta: [lng, lat].
        return [(float) $lng, (float) $lat];
    }
}
