<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Infrastructure\Sap\NullSapClient;
use Psr\Log\AbstractLogger;

/**
 * Cliente SAP nulo (binding por defecto): no habla con SAP, solo deja rastro en el log y devuelve
 * cero pedidos. PHP puro con un logger espía en memoria.
 */

/**
 * Logger espía que acumula los mensajes registrados.
 */
function nullSapClientLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };
}

/**
 * Traspaso de stock de ejemplo.
 */
function nullSapClientTransfer(): StockTransfer
{
    return StockTransfer::fromReception(
        StockTransferId::generate(),
        'REC-NULL-1',
        'SHIP-NULL-1',
        SapMaterial::of('MAT-100'),
        SapCenter::of('C001'),
        SapWarehouse::of('A01'),
        3,
    );
}

/**
 * Aviso a SAP de ejemplo.
 */
function nullSapClientNotification(): SapNotification
{
    return SapNotification::register(
        SapNotificationId::generate(),
        '0199c222-0000-7000-8000-000000000001',
        'execution.shipment_delivered',
        'SHP-1',
        [],
        new DateTimeImmutable,
        true,
        new DateTimeImmutable,
    );
}

it('deja rastro del traspaso descartado con sus datos', function (): void {
    $logger = nullSapClientLogger();
    $transfer = nullSapClientTransfer();

    (new NullSapClient($logger))->pushStockTransfer($transfer);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('Stock transfer discarded by NullSapClient')
        ->and($logger->records[0]['context']['material'])->toBe('MAT-100')
        ->and($logger->records[0]['context']['center'])->toBe('C001')
        ->and($logger->records[0]['context']['warehouse'])->toBe('A01')
        ->and($logger->records[0]['context']['quantity'])->toBe(3)
        ->and($logger->records[0]['context']['shipment_ref'])->toBe('SHIP-NULL-1');
});

it('devuelve cero pedidos y deja rastro', function (): void {
    $logger = nullSapClientLogger();

    $orders = (new NullSapClient($logger))->fetchOrders();

    expect($orders)->toBe([])
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('NullSapClient returned no orders');
});

it('responde 204 al avisar un evento, sin salir de la plataforma, y deja rastro', function (): void {
    $logger = nullSapClientLogger();
    $notification = nullSapClientNotification();

    $response = (new NullSapClient($logger))->notifyEvent($notification);

    expect($response->status)->toBe(204)
        ->and($response->body)->toBe('')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('SAP notification discarded by NullSapClient')
        ->and($logger->records[0]['context']['event_name'])->toBe('execution.shipment_delivered');
});

it('funciona sin logger (rama del logger nulo) en las tres operaciones', function (): void {
    $client = new NullSapClient;

    $client->pushStockTransfer(nullSapClientTransfer());
    $response = $client->notifyEvent(nullSapClientNotification());

    expect($client->fetchOrders())->toBe([])
        ->and($response->status)->toBe(204);
});
