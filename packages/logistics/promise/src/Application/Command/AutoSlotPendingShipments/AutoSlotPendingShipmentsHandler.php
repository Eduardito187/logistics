<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\AutoSlotPendingShipments;

use Logistics\Promise\Application\Command\ReserveDeliveryDate\ReserveDeliveryDate;
use Logistics\Promise\Domain\Contract\PendingSlotShipmentSource;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Handler del barrido de auto-slot: enumera los envíos sloteables (fecha sin ventana) y despacha un
 * `ReserveDeliveryDate` por cada uno a través del CommandBus.
 *
 * Cada reserva corre en su propia transacción (la que abre el decorador transaccional del CommandBus), así
 * un envío que no se pueda slotear —día bloqueado, sin ventana con cupo, o una carrera— no tumba el resto
 * del barrido: se cuenta como "sin ventana" y se sigue. Expone los contadores de la última corrida para
 * monitoreo. No se despacha por el bus; lo invoca directo el comando de consola.
 *
 * @implements CommandHandler<AutoSlotPendingShipments>
 */
final class AutoSlotPendingShipmentsHandler implements CommandHandler
{
    private int $lastSlotted = 0;

    private int $lastUnslotted = 0;

    public function __construct(
        private readonly PendingSlotShipmentSource $source,
        private readonly CommandBus $commandBus,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AutoSlotPendingShipments) {
            throw PromiseException::because('AutoSlotPendingShipmentsHandler solo maneja AutoSlotPendingShipments');
        }

        $this->lastSlotted = 0;
        $this->lastUnslotted = 0;

        foreach ($this->source->pendingSlotShipments($command->limit) as $shipment) {
            try {
                $this->commandBus->dispatch(new ReserveDeliveryDate($shipment['shipment_id'], $shipment['date']));
                $this->lastSlotted++;
            } catch (PromiseException) {
                // Día no despachable, sin ventana con cupo, o carrera que ya lo sloteó: queda en la cola
                // "Unslotted". No es un error del barrido; se cuenta y se sigue con los demás.
                $this->lastUnslotted++;
            }
        }
    }

    /**
     * Envíos sloteados en la última corrida.
     */
    public function lastSlotted(): int
    {
        return $this->lastSlotted;
    }

    /**
     * Envíos que quedaron sin ventana en la última corrida (a la cola "Unslotted").
     */
    public function lastUnslotted(): int
    {
        return $this->lastUnslotted;
    }
}
