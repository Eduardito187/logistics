<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\Event\StockTransferAcknowledged;
use Logistics\SapConnector\Domain\Event\StockTransferEnqueued;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Domain\ValueObject\TransferKind;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;

/**
 * Encola un traspaso de ejemplo desde una recepción.
 */
function enqueueTransfer(string $eventId = 'REC-0001', int $quantity = 1): StockTransfer
{
    return StockTransfer::fromReception(
        StockTransferId::generate(),
        $eventId,
        'SHIP-0001',
        SapMaterial::of('MAT-100'),
        SapCenter::of('C001'),
        SapWarehouse::of('A01'),
        $quantity,
    );
}

it('encola un traspaso en estado Pending desde una recepción', function (): void {
    $transfer = enqueueTransfer(quantity: 3);

    expect($transfer->status())->toBe(TransferStatus::Pending)
        ->and($transfer->eventId())->toBe('REC-0001')
        ->and($transfer->shipmentRef())->toBe('SHIP-0001')
        ->and($transfer->material()->value)->toBe('MAT-100')
        ->and($transfer->center()->value)->toBe('C001')
        ->and($transfer->warehouse()->value)->toBe('A01')
        ->and($transfer->quantity())->toBe(3)
        ->and($transfer->lastError())->toBeNull();
});

it('graba un evento StockTransferEnqueued con la clave de idempotencia', function (): void {
    $transfer = enqueueTransfer();

    $events = $transfer->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(StockTransferEnqueued::class);

    /** @var StockTransferEnqueued $event */
    $event = $events[0];
    expect($event->eventName())->toBe('sap_connector.stock_transfer_enqueued')
        ->and($event->payload()['reception_event_id'])->toBe('REC-0001')
        ->and($event->payload()['material'])->toBe('MAT-100')
        ->and($event->payload()['quantity'])->toBe(1);
});

it('exige una cantidad de al menos 1', function (): void {
    expect(fn () => enqueueTransfer(quantity: 0))
        ->toThrow(SapConnectorException::class, 'al menos 1');
});

it('exige un event_id de recepción no vacío (clave de idempotencia)', function (): void {
    expect(fn () => enqueueTransfer(eventId: '  '))
        ->toThrow(SapConnectorException::class, 'event_id');
});

it('conserva el event_id como clave de idempotencia a nivel agregado', function (): void {
    // Dos traspasos nacidos de la misma recepción comparten la clave de idempotencia (event_id),
    // que es con la que la aplicación y el índice único de la tabla evitan duplicar.
    $first = enqueueTransfer(eventId: 'REC-DUP');
    $second = enqueueTransfer(eventId: 'REC-DUP');

    expect($first->eventId())->toBe($second->eventId())
        ->and($first->id()->equals($second->id()))->toBeFalse();
});

it('marca el traspaso como Sent desde Pending y limpia el error', function (): void {
    $transfer = enqueueTransfer();
    $transfer->releaseEvents();

    $transfer->markSent();

    expect($transfer->status())->toBe(TransferStatus::Sent)
        ->and($transfer->lastError())->toBeNull()
        ->and($transfer->releaseEvents())->toBe([]);
});

it('confirma el traspaso desde Sent y graba StockTransferAcknowledged', function (): void {
    $transfer = enqueueTransfer();
    $transfer->releaseEvents();
    $transfer->markSent();

    $transfer->acknowledge();

    $events = $transfer->releaseEvents();
    expect($transfer->status())->toBe(TransferStatus::Acknowledged)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(StockTransferAcknowledged::class);

    /** @var StockTransferAcknowledged $event */
    $event = $events[0];
    expect($event->eventName())->toBe('sap_connector.stock_transfer_acknowledged')
        ->and($event->payload()['reception_event_id'])->toBe('REC-0001');
});

it('rechaza confirmar un traspaso que no fue enviado', function (): void {
    $transfer = enqueueTransfer();

    expect(fn () => $transfer->acknowledge())
        ->toThrow(SapConnectorException::class, 'transición inválida a Acknowledged');
});

it('marca el traspaso como Failed con el motivo y permite reintento', function (): void {
    $transfer = enqueueTransfer();
    $transfer->releaseEvents();
    $transfer->markSent();

    $transfer->markFailed('SAP timeout');

    expect($transfer->status())->toBe(TransferStatus::Failed)
        ->and($transfer->lastError())->toBe('SAP timeout');

    // Reintento: desde Failed se puede volver a Sent (es despachable).
    $transfer->markSent();
    expect($transfer->status())->toBe(TransferStatus::Sent)
        ->and($transfer->lastError())->toBeNull();
});

it('no permite marcar como Failed un traspaso ya confirmado', function (): void {
    $transfer = enqueueTransfer();
    $transfer->markSent();
    $transfer->acknowledge();

    expect(fn () => $transfer->markFailed('tarde'))
        ->toThrow(SapConnectorException::class, 'ya confirmado');
});

it('reconstituye sin grabar eventos', function (): void {
    $transfer = StockTransfer::reconstitute(
        StockTransferId::generate(),
        'REC-9',
        'SHIP-9',
        SapMaterial::of('MAT-9'),
        SapCenter::of('C009'),
        SapWarehouse::of('A09'),
        5,
        TransferStatus::Sent,
        null,
    );

    expect($transfer->releaseEvents())->toBe([])
        ->and($transfer->status())->toBe(TransferStatus::Sent)
        ->and($transfer->quantity())->toBe(5);
});

it('nace como traspaso de stock propio (Owned) por defecto, sin dueño', function (): void {
    $transfer = enqueueTransfer();

    expect($transfer->kind())->toBe(TransferKind::Owned)
        ->and($transfer->ownerPartyId())->toBeNull()
        ->and($transfer->isConsignment())->toBeFalse()
        ->and($transfer->kind()->postsOwnershipToSap())->toBeTrue();
});

it('encola un traspaso de consignación con el seller dueño del stock', function (): void {
    $transfer = StockTransfer::fromReception(
        StockTransferId::generate(),
        'REC-CONS-1',
        'SHIP-CONS-1',
        SapMaterial::of('MAT-900'),
        SapCenter::of('C001'),
        SapWarehouse::of('A01'),
        2,
        TransferKind::Consignment,
        '0199bdaa-0000-7000-8000-000000000abc',
    );

    expect($transfer->kind())->toBe(TransferKind::Consignment)
        ->and($transfer->isConsignment())->toBeTrue()
        ->and($transfer->ownerPartyId())->toBe('0199bdaa-0000-7000-8000-000000000abc')
        ->and($transfer->kind()->postsOwnershipToSap())->toBeFalse();
});

it('exige el seller dueño en una consignación', function (): void {
    expect(fn () => StockTransfer::fromReception(
        StockTransferId::generate(),
        'REC-CONS-2',
        'SHIP-CONS-2',
        SapMaterial::of('MAT-900'),
        SapCenter::of('C001'),
        SapWarehouse::of('A01'),
        1,
        TransferKind::Consignment,
        null,
    ))->toThrow(SapConnectorException::class, 'consignación exige el id del seller');
});

it('rechaza un dueño en un traspaso de stock propio (Owned)', function (): void {
    expect(fn () => StockTransfer::fromReception(
        StockTransferId::generate(),
        'REC-CONS-3',
        'SHIP-CONS-3',
        SapMaterial::of('MAT-900'),
        SapCenter::of('C001'),
        SapWarehouse::of('A01'),
        1,
        TransferKind::Owned,
        '0199bdaa-0000-7000-8000-000000000abc',
    ))->toThrow(SapConnectorException::class, 'stock propio (Owned) no puede tener un seller');
});
