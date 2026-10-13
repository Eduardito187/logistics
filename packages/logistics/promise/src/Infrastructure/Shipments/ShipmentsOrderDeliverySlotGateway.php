<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Shipments;

use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Command\SetDeliverySlot\SetDeliverySlot;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Implementación real del puerto hacia envíos: lee la franja vigente del pedido con la consulta pública
 * de shipments y la fija con su comando `SetDeliverySlot`.
 *
 * El comando corre por el CommandBus, dentro de la MISMA transacción que la operación de promesa (el
 * dispatch anidado anida un savepoint), de modo que el consumo de cupo y la asignación de la franja al
 * envío son atómicos: o se confirman juntos o no se confirma nada. El cruce entre contextos es por el
 * bus y por primitivos; este adaptador no importa infraestructura de shipments ni su dominio.
 */
final readonly class ShipmentsOrderDeliverySlotGateway implements OrderDeliverySlotGateway
{
    public function __construct(
        private QueryBus $queryBus,
        private CommandBus $commandBus,
    ) {}

    public function currentSlot(string $orderId): ?array
    {
        /** @var ShipmentView|null $view */
        $view = $this->queryBus->ask(new FindShipment($orderId));

        if ($view === null
            || $view->deliveryDate === null
            || $view->deliveryWindowStart === null
            || $view->deliveryWindowEnd === null) {
            return null;
        }

        return [
            'date' => $view->deliveryDate,
            'window_start' => $view->deliveryWindowStart,
            'window_end' => $view->deliveryWindowEnd,
        ];
    }

    public function assignSlot(string $orderId, string $date, string $windowStart, string $windowEnd): void
    {
        try {
            $this->commandBus->dispatch(new SetDeliverySlot($orderId, $date, $windowStart, $windowEnd));
        } catch (ShipmentsException $exception) {
            // Traduce el error del contexto de envíos al lenguaje de promise (pedido inexistente o ya
            // fuera de recepción), para que el canal reciba un error de promesa coherente y no un 500.
            throw PromiseException::because($exception->getMessage());
        }
    }
}
