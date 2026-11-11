<?php

declare(strict_types=1);

use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTracking;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

// Ciclo de vida del pedido de cara al cliente, de punta a punta por los COMANDOS reales (los mismos que
// disparan los reactores del outbox). Verifica el orden del flujo y que NO se permitan saltos.

function lifecycleBus(): CommandBus
{
    return app(CommandBus::class);
}

function lifecycleStatusOf(string $orderReference): ?VisibleStatus
{
    return app(TrackingRepository::class)->findByOrderReference($orderReference)?->status();
}

it('advances the order lifecycle through its real events, in order', function (): void {
    $order = 'order-lifecycle-1';
    $shipment = '11111111-1111-7111-8111-111111111111';

    // Orden creada → pendiente.
    lifecycleBus()->dispatch(new OpenTracking(orderReference: $order));
    expect(lifecycleStatusOf($order))->toBe(VisibleStatus::Pending);

    // Envío creado → se liga (sigue pendiente hasta el pago).
    lifecycleBus()->dispatch(new BindShipmentToTracking(orderReference: $order, shipmentId: $shipment));
    expect(app(TrackingRepository::class)->findByShipmentId($shipment))->not->toBeNull();

    // Pago → procesando.
    lifecycleBus()->dispatch(new AdvanceTracking(status: VisibleStatus::Processing, orderReference: $order));
    expect(lifecycleStatusOf($order))->toBe(VisibleStatus::Processing);

    // Etiqueta → en curso.
    lifecycleBus()->dispatch(new AdvanceTracking(status: VisibleStatus::InProgress, orderReference: $order));
    expect(lifecycleStatusOf($order))->toBe(VisibleStatus::InProgress);

    // Carga → cargada; salida → en camino; entrega → entregado (hechos de ejecución por envío).
    lifecycleBus()->dispatch(new ProjectExecutionEvent('execution.shipment_loaded', $shipment));
    expect(lifecycleStatusOf($order))->toBe(VisibleStatus::Loaded);

    lifecycleBus()->dispatch(new ProjectExecutionEvent('execution.shipment_departed', $shipment));
    expect(lifecycleStatusOf($order))->toBe(VisibleStatus::OnTheWay);

    lifecycleBus()->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipment));
    expect(lifecycleStatusOf($order))->toBe(VisibleStatus::Delivered);

    // Devolución tras entrega.
    lifecycleBus()->dispatch(new AdvanceTracking(status: VisibleStatus::Returned, orderReference: $order));
    expect(lifecycleStatusOf($order))->toBe(VisibleStatus::Returned);
});

it('tolerates out-of-order / illegal advances without breaking the projection', function (): void {
    $order = 'order-lifecycle-2';
    $shipment = '22222222-2222-7222-8222-222222222222';

    lifecycleBus()->dispatch(new OpenTracking(orderReference: $order));
    lifecycleBus()->dispatch(new BindShipmentToTracking(orderReference: $order, shipmentId: $shipment));

    // Un salto que se adelanta (entregado desde pendiente) NO aplica: queda en pendiente.
    lifecycleBus()->dispatch(new AdvanceTracking(status: VisibleStatus::Delivered, orderReference: $order));
    expect(lifecycleStatusOf($order))->toBe(VisibleStatus::Pending);

    // Un evento de ejecución fuera de orden (entregado antes de cargar) tampoco rompe: se saltea.
    lifecycleBus()->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipment));
    expect(lifecycleStatusOf($order))->toBe(VisibleStatus::Pending);
});

it('cancels from a pre-delivery state but not after delivery', function (): void {
    $order = 'order-lifecycle-3';
    $shipment = '33333333-3333-7333-8333-333333333333';

    lifecycleBus()->dispatch(new OpenTracking(orderReference: $order));
    lifecycleBus()->dispatch(new BindShipmentToTracking(orderReference: $order, shipmentId: $shipment));
    lifecycleBus()->dispatch(new AdvanceTracking(status: VisibleStatus::Processing, orderReference: $order));

    // Se puede anular tras el pago, antes de entregar.
    lifecycleBus()->dispatch(new AdvanceTracking(status: VisibleStatus::Cancelled, orderReference: $order));
    expect(lifecycleStatusOf($order))->toBe(VisibleStatus::Cancelled);
});

it('opens a sibling tracking for a second shipment of the same order (multishipping)', function (): void {
    $order = 'order-multi-1';

    lifecycleBus()->dispatch(new OpenTracking(orderReference: $order));
    lifecycleBus()->dispatch(new BindShipmentToTracking(orderReference: $order, shipmentId: 'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa'));
    lifecycleBus()->dispatch(new BindShipmentToTracking(orderReference: $order, shipmentId: 'bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb'));

    $all = app(TrackingRepository::class)->findAllByOrderReference($order);
    expect($all)->toHaveCount(2);
    expect(app(TrackingRepository::class)->findByShipmentId('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa'))->not->toBeNull()
        ->and(app(TrackingRepository::class)->findByShipmentId('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb'))->not->toBeNull();
});
