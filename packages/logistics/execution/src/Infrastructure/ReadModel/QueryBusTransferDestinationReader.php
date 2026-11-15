<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Logistics\Execution\Application\Contract\TransferDestinationReader;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Throwable;

/**
 * Resuelve el destino de un traspaso preguntándole al contexto de envíos por su consulta pública
 * (`FindShipment` → `ShipmentView::deliveryMode` / `destinationNodeId`): la regla de qué es un traspaso vive
 * en un solo lugar (envíos) y la ejecución no la duplica.
 *
 * Tolerante: un id que no es UUID, un envío inexistente o un fallo de la consulta devuelven null, o sea «no
 * es un traspaso»: la entrega en tienda se registra igual y la entrega simplemente no se cierra sola.
 *
 * Al extraer ejecución a un servicio aparte, esta implementación pasa a llamar a la API de envíos; la puerta
 * de Application no cambia.
 */
final readonly class QueryBusTransferDestinationReader implements TransferDestinationReader
{
    /** Modalidad de entrega con la que envíos marca un traspaso interno (valor de `delivery_mode`). */
    private const string TRANSFER_MODE = 'transfer';

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function transferDestinationNodeId(string $shipmentId): ?string
    {
        if (preg_match(self::UUID_PATTERN, $shipmentId) !== 1) {
            return null;
        }

        try {
            $shipment = $this->queryBus->ask(new FindShipment($shipmentId));
        } catch (Throwable) {
            return null;
        }

        if (!$shipment instanceof ShipmentView) {
            return null;
        }

        $destination = trim((string) ($shipment->destinationNodeId ?? ''));
        if ($destination === '' || strtolower((string) $shipment->deliveryMode) !== self::TRANSFER_MODE) {
            return null;
        }

        return $destination;
    }
}
