<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Domain\Event\StockTransferAcknowledged;
use Logistics\SapConnector\Domain\Event\StockTransferEnqueued;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\SapConnector\Infrastructure\Outbox\SapMessageDispatcher;
use Logistics\Shared\Domain\Event\BaseDomainEvent;
use Logistics\Shared\Domain\Event\DomainEvent;

/**
 * Pruebas del adapter del relay hacia SAP (evento → outbox → SAP, ADR 002), con dobles en memoria del
 * repositorio de traspasos y del puerto SapClient: sin Laravel ni base de datos.
 */

/**
 * Repositorio de traspasos en memoria para las pruebas del dispatcher.
 */
function inMemoryTransferRepository(StockTransfer ...$seed): StockTransferRepository
{
    return new class(...$seed) implements StockTransferRepository
    {
        /** @var array<string, StockTransfer> */
        private array $byId = [];

        public function __construct(StockTransfer ...$seed)
        {
            foreach ($seed as $transfer) {
                $this->byId[$transfer->id()->toString()] = $transfer;
            }
        }

        public function nextIdentity(): StockTransferId
        {
            return StockTransferId::generate();
        }

        public function save(StockTransfer $transfer): void
        {
            $this->byId[$transfer->id()->toString()] = $transfer;
        }

        public function findById(StockTransferId $id): ?StockTransfer
        {
            return $this->byId[$id->toString()] ?? null;
        }

        public function findByEventId(string $eventId): ?StockTransfer
        {
            foreach ($this->byId as $transfer) {
                if ($transfer->eventId() === trim($eventId)) {
                    return $transfer;
                }
            }

            return null;
        }

        public function pending(): array
        {
            return array_values(array_filter(
                $this->byId,
                static fn (StockTransfer $t): bool => $t->status()->isDispatchable(),
            ));
        }

        public function failed(): array
        {
            return array_values(array_filter(
                $this->byId,
                static fn (StockTransfer $t): bool => $t->status() === TransferStatus::Failed,
            ));
        }
    };
}

/**
 * Puerto SAP en memoria: cuenta los empujes y, opcionalmente, falla.
 */
function recordingSapClient(bool $fails = false): SapClient
{
    return new class($fails) implements SapClient
    {
        public int $pushed = 0;

        public function __construct(private readonly bool $fails) {}

        public function pushStockTransfer(StockTransfer $transfer): void
        {
            $this->pushed++;
            if ($this->fails) {
                throw SapConnectorException::because('SAP caído');
            }
        }

        public function fetchOrders(): array
        {
            return [];
        }
    };
}

/**
 * Construye el mensaje de outbox de un evento de encolado, tal como lo graba EnqueueStockTransfer.
 */
function enqueuedOutboxMessage(StockTransfer $transfer): OutboxMessage
{
    $event = new StockTransferEnqueued(
        $transfer->id()->toString(),
        $transfer->eventId(),
        $transfer->shipmentRef(),
        $transfer->material()->value,
        $transfer->center()->value,
        $transfer->warehouse()->value,
        $transfer->quantity(),
    );

    return OutboxMessage::fromDomainEvent($event, 'sap.stock_transfer', new DateTimeImmutable);
}

function pendingTransfer(string $eventId = 'REC-5001'): StockTransfer
{
    $transfer = StockTransfer::fromReception(
        StockTransferId::generate(),
        $eventId,
        'SHIP-5001',
        SapMaterial::of('MAT-900'),
        SapCenter::of('C001'),
        SapWarehouse::of('A01'),
        2,
    );
    $transfer->releaseEvents();

    return $transfer;
}

it('empuja a SAP un traspaso pendiente y lo deja Sent', function (): void {
    $transfer = pendingTransfer();
    $repository = inMemoryTransferRepository($transfer);
    $sap = recordingSapClient();

    $dispatcher = new SapMessageDispatcher($repository, $sap);
    $dispatcher->dispatch(enqueuedOutboxMessage($transfer));

    expect($sap->pushed)->toBe(1)
        ->and($repository->findByEventId('REC-5001')?->status())->toBe(TransferStatus::Sent);
});

it('es idempotente: no reenvía un traspaso que ya fue enviado (reentrega del outbox)', function (): void {
    $transfer = pendingTransfer();
    $transfer->markSent();
    $repository = inMemoryTransferRepository($transfer);
    $sap = recordingSapClient();

    $dispatcher = new SapMessageDispatcher($repository, $sap);
    $dispatcher->dispatch(enqueuedOutboxMessage($transfer));

    expect($sap->pushed)->toBe(0)
        ->and($repository->findByEventId('REC-5001')?->status())->toBe(TransferStatus::Sent);
});

it('marca Failed y relanza cuando SAP falla, para que el relay reintente', function (): void {
    $transfer = pendingTransfer();
    $repository = inMemoryTransferRepository($transfer);
    $sap = recordingSapClient(fails: true);

    $dispatcher = new SapMessageDispatcher($repository, $sap);

    expect(fn () => $dispatcher->dispatch(enqueuedOutboxMessage($transfer)))
        ->toThrow(SapConnectorException::class, 'SAP caído');

    $failed = $repository->findByEventId('REC-5001');
    expect($failed?->status())->toBe(TransferStatus::Failed)
        ->and($failed?->lastError())->toBe('SAP caído');
});

it('reintenta un traspaso Failed en una reentrega posterior', function (): void {
    $transfer = pendingTransfer();
    $transfer->markFailed('SAP caído');
    $repository = inMemoryTransferRepository($transfer);
    $sap = recordingSapClient();

    $dispatcher = new SapMessageDispatcher($repository, $sap);
    $dispatcher->dispatch(enqueuedOutboxMessage($transfer));

    expect($sap->pushed)->toBe(1)
        ->and($repository->findByEventId('REC-5001')?->status())->toBe(TransferStatus::Sent);
});

it('ignora eventos del conector que no son de encolado (no reenvía confirmaciones)', function (): void {
    $transfer = pendingTransfer();
    $repository = inMemoryTransferRepository($transfer);
    $sap = recordingSapClient();

    $ackEvent = new StockTransferAcknowledged($transfer->id()->toString(), $transfer->eventId());
    $message = OutboxMessage::fromDomainEvent($ackEvent, 'sap.stock_transfer', new DateTimeImmutable);

    $dispatcher = new SapMessageDispatcher($repository, $sap);
    $dispatcher->dispatch($message);

    expect($sap->pushed)->toBe(0)
        ->and($repository->findByEventId('REC-5001')?->status())->toBe(TransferStatus::Pending);
});

it('es un no-op cuando no existe el traspaso del event_id (defensivo)', function (): void {
    $transfer = pendingTransfer();
    $repository = inMemoryTransferRepository(); // vacío: el traspaso no está persistido
    $sap = recordingSapClient();

    $dispatcher = new SapMessageDispatcher($repository, $sap);
    $dispatcher->dispatch(enqueuedOutboxMessage($transfer));

    expect($sap->pushed)->toBe(0);
});

it('ignora un evento sin reception_event_id en el payload', function (): void {
    $repository = inMemoryTransferRepository();
    $sap = recordingSapClient();

    $event = new readonly class('agg-1') extends BaseDomainEvent
    {
        public function eventName(): string
        {
            return 'sap_connector.stock_transfer_enqueued';
        }

        public function payload(): array
        {
            return ['stock_transfer_id' => $this->aggregateId];
        }
    };
    assert($event instanceof DomainEvent);
    $message = OutboxMessage::fromDomainEvent($event, 'sap.stock_transfer', new DateTimeImmutable);

    $dispatcher = new SapMessageDispatcher($repository, $sap);
    $dispatcher->dispatch($message);

    expect($sap->pushed)->toBe(0);
});
