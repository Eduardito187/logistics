<?php

declare(strict_types=1);

use Logistics\Promise\Application\Command\AutoSlotPendingShipments\AutoSlotPendingShipments;
use Logistics\Promise\Application\Command\AutoSlotPendingShipments\AutoSlotPendingShipmentsHandler;
use Logistics\Promise\Application\Command\ReserveDeliveryDate\ReserveDeliveryDate;
use Logistics\Promise\Domain\Contract\PendingSlotShipmentSource;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;

/*
| Barrido de auto-slot (Módulo 1, Fase 1.1): enumera envíos sloteables (fecha sin ventana) y despacha un
| ReserveDeliveryDate por cada uno. Un envío que no se pueda slotear no tumba el barrido: se cuenta como
| "unslotted". Todo en memoria, sin base.
*/

const APS_DATE = '2026-10-20';

/**
 * Fuente en memoria de envíos sloteables.
 *
 * @param list<array{shipment_id: string, date: string}> $items
 */
function apsSource(array $items): PendingSlotShipmentSource
{
    return new class($items) implements PendingSlotShipmentSource
    {
        /** @param list<array{shipment_id: string, date: string}> $items */
        public function __construct(private array $items) {}

        public function pendingSlotShipments(int $limit): iterable
        {
            return array_slice($this->items, 0, $limit);
        }
    };
}

/**
 * CommandBus que captura; lanza PromiseException al recibir un ReserveDeliveryDate del envío $failFor.
 */
function apsBus(?string $failFor = null): CommandBus
{
    return new class($failFor) implements CommandBus
    {
        /** @var list<Command> */
        public array $dispatched = [];

        public function __construct(private readonly ?string $failFor) {}

        public function dispatch(Command $command): void
        {
            $this->dispatched[] = $command;

            if ($command instanceof ReserveDeliveryDate && $command->shipmentId === $this->failFor) {
                throw PromiseException::noWindow('sin ventana con cupo');
            }
        }
    };
}

it('slotea cada envío pendiente y cuenta los sloteados', function (): void {
    $bus = apsBus();
    $handler = new AutoSlotPendingShipmentsHandler(apsSource([
        ['shipment_id' => 'ship-1', 'date' => APS_DATE],
        ['shipment_id' => 'ship-2', 'date' => APS_DATE],
    ]), $bus);

    $handler->handle(new AutoSlotPendingShipments);

    expect($bus->dispatched)->toHaveCount(2)
        ->and($handler->lastSlotted())->toBe(2)
        ->and($handler->lastUnslotted())->toBe(0);
});

it('cuenta como unslotted el envío que no se puede slotear y sigue con el resto', function (): void {
    $bus = apsBus(failFor: 'ship-2');
    $handler = new AutoSlotPendingShipmentsHandler(apsSource([
        ['shipment_id' => 'ship-1', 'date' => APS_DATE],
        ['shipment_id' => 'ship-2', 'date' => APS_DATE],
        ['shipment_id' => 'ship-3', 'date' => APS_DATE],
    ]), $bus);

    $handler->handle(new AutoSlotPendingShipments);

    expect($handler->lastSlotted())->toBe(2)
        ->and($handler->lastUnslotted())->toBe(1)
        ->and($bus->dispatched)->toHaveCount(3);
});

it('respeta el límite del lote', function (): void {
    $bus = apsBus();
    $handler = new AutoSlotPendingShipmentsHandler(apsSource([
        ['shipment_id' => 'ship-1', 'date' => APS_DATE],
        ['shipment_id' => 'ship-2', 'date' => APS_DATE],
        ['shipment_id' => 'ship-3', 'date' => APS_DATE],
    ]), $bus);

    $handler->handle(new AutoSlotPendingShipments(limit: 2));

    expect($handler->lastSlotted())->toBe(2);
});
