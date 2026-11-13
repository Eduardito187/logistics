<?php

declare(strict_types=1);

use Logistics\Promise\Application\Query\ListUnslottedShipments\ListUnslottedShipments;
use Logistics\Promise\Application\Query\ListUnslottedShipments\ListUnslottedShipmentsHandler;
use Logistics\Promise\Application\ReadModel\UnslottedShipmentView;
use Logistics\Promise\Domain\Contract\PendingSlotShipmentSource;

/*
| Cola "Unslotted" (Módulo 1): proyecta los envíos sloteables (fecha sin ventana) del puerto a vistas
| planas. Solo lectura, en memoria.
*/

const LUS_DATE = '2026-10-20';

/**
 * @param list<array{shipment_id: string, date: string}> $items
 */
function lusSource(array $items): PendingSlotShipmentSource
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

it('proyecta los envíos sloteables a vistas planas', function (): void {
    $handler = new ListUnslottedShipmentsHandler(lusSource([
        ['shipment_id' => 'ship-1', 'date' => LUS_DATE],
        ['shipment_id' => 'ship-2', 'date' => LUS_DATE],
    ]));

    $views = $handler->handle(new ListUnslottedShipments);

    expect($views)->toHaveCount(2)
        ->and($views[0])->toBeInstanceOf(UnslottedShipmentView::class)
        ->and($views[0]->toArray())->toBe(['shipment_id' => 'ship-1', 'date' => LUS_DATE]);
});

it('devuelve vacío cuando no hay envíos pendientes de slot', function (): void {
    $handler = new ListUnslottedShipmentsHandler(lusSource([]));

    expect($handler->handle(new ListUnslottedShipments))->toBe([]);
});
