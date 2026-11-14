<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Contract\ReturnCoverageReader;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Outbox\ReturnedOnReturnInitiated;

// Devolución PARCIAL: un pedido de 3 productos del que se devuelven 1 o 2 sigue «entregado» (los que no se
// devolvieron conservan su historia). Recién al devolverse completo pasa a «devuelta».
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Pedido entregado con las líneas dadas (sku => cantidad). Devuelve la referencia de la orden.
 *
 * @param array<string, int> $lines
 */
function prtDeliveredOrder(array $lines): string
{
    $order = 'ORD-PRT-'.Str::random(8);
    $shipment = ShipmentRecord::factory()->create(['external_id' => $order, 'order_reference' => $order]);

    foreach ($lines as $sku => $quantity) {
        DB::table('shipment_lines')->insert([
            'id' => (string) Str::uuid7(),
            'shipment_id' => $shipment->id,
            'sku' => $sku,
            'product_name' => 'Producto '.$sku,
            'quantity' => $quantity,
        ]);
    }

    app(CommandBus::class)->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipment->id));

    return $order;
}

/**
 * Registra una devolución del pedido (sku => cantidad) y dispara el reactor, como lo haría el outbox.
 *
 * @param array<string, int> $lines
 */
function prtReturn(string $order, array $lines): void
{
    $returnId = (string) Str::uuid7();
    DB::table('returns')->insert([
        'id' => $returnId,
        'original_reference' => $order,
        'fulfillment_mode' => 'owned',
        'warehouse_id' => (string) Str::uuid7(),
        'kind' => 'customer_return',
        'status' => 'INITIATED',
    ]);
    foreach ($lines as $sku => $quantity) {
        DB::table('return_lines')->insert([
            'id' => (string) Str::uuid7(),
            'return_id' => $returnId,
            'sku' => $sku,
            'quantity' => $quantity,
            'reason' => 'no le gustó',
        ]);
    }

    app(ReturnedOnReturnInitiated::class)->dispatch(OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'returns.return',
        $returnId,
        'returns.return_initiated',
        ['original_reference' => $order],
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    ));
}

function prtStatus(string $order): ?VisibleStatus
{
    return app(TrackingRepository::class)->findByOrderReference($order)?->status();
}

it('una devolución parcial deja el pedido entregado y registra cuánto se devolvió de cada producto', function (): void {
    $order = prtDeliveredOrder(['SKU-A' => 1, 'SKU-B' => 1, 'SKU-C' => 1]);

    prtReturn($order, ['SKU-A' => 1, 'SKU-B' => 1]);

    expect(prtStatus($order))->toBe(VisibleStatus::Delivered)
        ->and(app(ReturnCoverageReader::class)->coverage($order))->toBe([
            'SKU-A' => ['delivered' => 1, 'returned' => 1],
            'SKU-B' => ['delivered' => 1, 'returned' => 1],
            'SKU-C' => ['delivered' => 1, 'returned' => 0],
        ]);
});

it('devolver parte de la cantidad de un producto también es parcial', function (): void {
    $order = prtDeliveredOrder(['SKU-A' => 3]);

    prtReturn($order, ['SKU-A' => 2]);

    expect(prtStatus($order))->toBe(VisibleStatus::Delivered);
});

it('al completarse la devolución en una segunda tanda el pedido pasa a devuelto', function (): void {
    $order = prtDeliveredOrder(['SKU-A' => 1, 'SKU-B' => 2]);

    prtReturn($order, ['SKU-A' => 1]);
    expect(prtStatus($order))->toBe(VisibleStatus::Delivered);

    prtReturn($order, ['SKU-B' => 2]);
    expect(prtStatus($order))->toBe(VisibleStatus::Returned);
});
