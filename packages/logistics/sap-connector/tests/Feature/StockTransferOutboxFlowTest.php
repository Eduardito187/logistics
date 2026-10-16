<?php

declare(strict_types=1);

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Infrastructure\Dispatch\RoutingMessageDispatcher;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfers;
use Logistics\SapConnector\Application\Command\EnqueueStockTransfer\EnqueueStockTransfer;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Event\StockTransferEnqueued;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\SapConnector\Infrastructure\Outbox\SapMessageDispatcher;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Pruebas de integración del camino evento → outbox → SAP del conector (ADR 002), contra PostgreSQL
 * real (logistics_test). Verifican que encolar un traspaso persiste el agregado Pending y graba el
 * evento en el outbox, que es idempotente por event_id de recepción, que el relay rutea esos eventos al
 * adapter del conector, y que el worker de reconciliación empuja a SAP (stub Null por defecto) sin
 * colgar el escaneo.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Crea un mapeo sku ↔ coordenada SAP para que el encolado pueda traducir.
 */
function seedMapping(string $sku = 'LG-OLED55'): MaterialMappingRecord
{
    return MaterialMappingRecord::factory()->create([
        'sku' => $sku,
        'sap_material' => '700123',
        'center' => 'C001',
        'warehouse' => 'W01',
    ]);
}

it('encola un traspaso Pending y graba el evento de encolado en el outbox', function (): void {
    seedMapping('LG-OLED55');

    app(CommandBus::class)->dispatch(new EnqueueStockTransfer(
        eventId: '01a13700-0000-7000-8000-000000007001',
        shipmentRef: 'SHP-7001',
        sku: 'LG-OLED55',
        quantity: 3,
    ));

    $transfer = StockTransferRecord::query()->where('event_id', '01a13700-0000-7000-8000-000000007001')->first();
    expect($transfer)->not->toBeNull()
        ->and($transfer->status)->toBe(TransferStatus::Pending->value)
        ->and($transfer->material)->toBe('700123')
        ->and($transfer->center)->toBe('C001')
        ->and($transfer->warehouse)->toBe('W01')
        ->and($transfer->quantity)->toBe(3);

    $message = OutboxMessageRecord::query()
        ->where('event_name', 'sap_connector.stock_transfer_enqueued')
        ->first();
    expect($message)->not->toBeNull()
        ->and($message->payload['reception_event_id'])->toBe('01a13700-0000-7000-8000-000000007001')
        ->and($message->payload['material'])->toBe('700123');
});

it('es idempotente por event_id: reencolar la misma recepción no duplica traspaso ni evento', function (): void {
    seedMapping('LG-OLED55');

    $command = new EnqueueStockTransfer('01a13700-0000-7000-8000-000000007002', 'SHP-7002', 'LG-OLED55', 1);
    app(CommandBus::class)->dispatch($command);
    app(CommandBus::class)->dispatch($command);

    expect(StockTransferRecord::query()->where('event_id', '01a13700-0000-7000-8000-000000007002')->count())->toBe(1)
        ->and(OutboxMessageRecord::query()
            ->where('event_name', 'sap_connector.stock_transfer_enqueued')
            ->count())->toBe(1);
});

it('rutea los eventos sap_connector. del outbox al adapter del conector', function (): void {
    $dispatcher = app(MessageDispatcher::class);

    // La clave de ruteo es literal "sap_connector." (con el punto): se lee el arreglo completo para no
    // partirla por la notación de puntos de config().
    $routes = config('logistics-outbox.routes');

    expect($dispatcher)->toBeInstanceOf(RoutingMessageDispatcher::class)
        ->and($routes['sap_connector.'] ?? null)->toBe(SapMessageDispatcher::class);
});

it('el relay empuja a SAP un traspaso encolado y lo deja Sent (stub Null por defecto)', function (): void {
    seedMapping('LG-OLED55');

    app(CommandBus::class)->dispatch(new EnqueueStockTransfer('01a13700-0000-7000-8000-000000007003', 'SHP-7003', 'LG-OLED55', 2));

    $record = StockTransferRecord::query()->where('event_id', '01a13700-0000-7000-8000-000000007003')->firstOrFail();

    // Simula el relay: reconstruye el mensaje del outbox y lo entrega al dispatcher ruteado.
    $event = new StockTransferEnqueued(
        $record->id,
        $record->event_id,
        $record->shipment_ref,
        $record->material,
        $record->center,
        $record->warehouse,
        $record->quantity,
    );
    app(MessageDispatcher::class)->dispatch(
        OutboxMessage::fromDomainEvent($event, 'sap.stock_transfer', new DateTimeImmutable),
    );

    expect(StockTransferRecord::query()->where('event_id', '01a13700-0000-7000-8000-000000007003')->value('status'))
        ->toBe(TransferStatus::Sent->value);
});

it('el worker NO postea a SAP una consignación de seller y la deja Sent', function (): void {
    // Regla dura (ADR 017): el stock de un seller (consignación) NUNCA se postea a SAP como material
    // propio de Dismac. Puerto SAP espía para verificar que no se lo invoca.
    $sap = new class implements SapClient
    {
        public int $pushed = 0;

        public function pushStockTransfer(StockTransfer $transfer): void
        {
            $this->pushed++;
        }

        public function fetchOrders(): array
        {
            return [];
        }
    };
    app()->instance(SapClient::class, $sap);

    StockTransferRecord::factory()->pending()->consignment()->create([
        'id' => StockTransferId::generate()->toString(),
        'event_id' => StockTransferId::generate()->toString(),
    ]);

    app(CommandBus::class)->dispatch(new DispatchPendingTransfers);

    expect($sap->pushed)->toBe(0)
        ->and(StockTransferRecord::query()->where('status', TransferStatus::Sent->value)->count())->toBe(1);
});

it('el worker de reconciliación empuja los pendientes a SAP y los deja Sent', function (): void {
    StockTransferRecord::factory()->pending()->create(['id' => StockTransferId::generate()->toString(), 'event_id' => StockTransferId::generate()->toString()]);
    StockTransferRecord::factory()->failed()->create(['id' => StockTransferId::generate()->toString(), 'event_id' => StockTransferId::generate()->toString()]);
    StockTransferRecord::factory()->acknowledged()->create(['id' => StockTransferId::generate()->toString(), 'event_id' => StockTransferId::generate()->toString()]);

    app(CommandBus::class)->dispatch(new DispatchPendingTransfers);

    // Pending y Failed se empujan (NullSapClient no falla) → Sent; el confirmado no se toca.
    expect(StockTransferRecord::query()->where('status', TransferStatus::Pending->value)->count())->toBe(0)
        ->and(StockTransferRecord::query()->where('status', TransferStatus::Failed->value)->count())->toBe(0)
        ->and(StockTransferRecord::query()->where('status', TransferStatus::Sent->value)->count())->toBe(2)
        ->and(StockTransferRecord::query()->where('status', TransferStatus::Acknowledged->value)->count())->toBe(1);
});
