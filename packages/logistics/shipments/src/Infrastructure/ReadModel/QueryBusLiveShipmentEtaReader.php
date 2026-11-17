<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Contract\LiveShipmentEtaReader;
use Throwable;

/**
 * Resuelve la ETA viva de un envío consultando el motor de telemetría por el QueryBus compartido, SIN
 * acoplar shipments a telemetry (la consulta se referencia por nombre; si el contexto no está presente en el
 * despliegue, degrada a vacío). Mismo criterio que usa execution para leer el estado vivo de la ruta.
 */
final readonly class QueryBusLiveShipmentEtaReader implements LiveShipmentEtaReader
{
    /** Consulta del motor de ETA viva por envío (telemetría), referenciada por nombre. */
    private const string ETA_QUERY = 'Logistics\\Telemetry\\Application\\Query\\GetShipmentEta\\GetShipmentEta';

    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function etasFor(array $shipmentIds): array
    {
        $class = self::ETA_QUERY;
        if (!class_exists($class)) {
            return [];
        }

        $etas = [];
        foreach (array_unique($shipmentIds) as $shipmentId) {
            $eta = $this->etaFor($class, $shipmentId);
            if ($eta !== null) {
                $etas[$shipmentId] = $eta;
            }
        }

        return $etas;
    }

    /**
     * ETA viva de un envío, o null si no hay motor, no está en ruta o la lectura falla.
     *
     * @param class-string $class
     */
    private function etaFor(string $class, string $shipmentId): ?string
    {
        if ($shipmentId === '') {
            return null;
        }

        $query = new $class($shipmentId);
        if (!$query instanceof Query) {
            return null;
        }

        try {
            $view = $this->queryBus->ask($query);
        } catch (Throwable) {
            return null;
        }

        if (!is_object($view) || !method_exists($view, 'toArray')) {
            return null;
        }

        $stops = $view->toArray()['stops'] ?? null;
        if (!is_array($stops)) {
            return null;
        }

        $first = $stops[0] ?? null;
        $eta = is_array($first) ? ($first['eta'] ?? null) : null;

        return is_string($eta) && $eta !== '' ? $eta : null;
    }
}
