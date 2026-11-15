<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Dispatch;

use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Throwable;

/**
 * Filtro común de los avisos AL CLIENTE: un traspaso interno de mercadería (`delivery_mode = transfer`) no
 * tiene cliente, así que no se le encola ningún aviso (ni cargado, ni en camino, ni entregado).
 *
 * La modalidad la resuelve envíos por su consulta pública (`FindShipment` → `ShipmentView::deliveryMode`), la
 * misma fuente que usa el aviso «listo para recojo»: la regla de qué es un traspaso vive en un solo lugar.
 *
 * Tolerante: sin bus de consultas, con un id que no es UUID (no se consulta: compararlo contra la columna
 * uuid haría fallar la consulta), con un envío inexistente o con la consulta caída responde «no es un
 * traspaso», o sea que el dispatcher se comporta como siempre.
 */
trait SkipsInternalTransfers
{
    private function isInternalTransfer(?QueryBus $queryBus, string $shipmentId): bool
    {
        if ($queryBus === null
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $shipmentId) !== 1) {
            return false;
        }

        try {
            $shipment = $queryBus->ask(new FindShipment($shipmentId));
        } catch (Throwable) {
            return false;
        }

        return $shipment instanceof ShipmentView
            && strtolower((string) $shipment->deliveryMode) === 'transfer';
    }
}
