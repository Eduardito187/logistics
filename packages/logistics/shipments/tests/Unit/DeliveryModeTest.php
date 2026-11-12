<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\ValueObject\DeliveryMode;
use Logistics\Shipments\Domain\ValueObject\OrderSource;

/**
 * Modalidad de entrega derivada del tipo del nodo destino (pedido #22 de la app).
 */
it('es recojo en tienda cuando el nodo destino es una tienda o un punto de recojo', function (): void {
    expect(DeliveryMode::forDestinationNodeType('store'))->toBe(DeliveryMode::StorePickup)
        ->and(DeliveryMode::forDestinationNodeType('pickup_point'))->toBe(DeliveryMode::StorePickup)
        ->and(DeliveryMode::forDestinationNodeType(' STORE '))->toBe(DeliveryMode::StorePickup);
});

it('es reparto a domicilio sin nodo, con un CD/almacén o con un nodo desconocido', function (): void {
    expect(DeliveryMode::forDestinationNodeType(null))->toBe(DeliveryMode::HomeDelivery)
        ->and(DeliveryMode::forDestinationNodeType('distribution_center'))->toBe(DeliveryMode::HomeDelivery)
        ->and(DeliveryMode::forDestinationNodeType('central_warehouse'))->toBe(DeliveryMode::HomeDelivery)
        ->and(DeliveryMode::forDestinationNodeType('locker'))->toBe(DeliveryMode::HomeDelivery);
});

it('solo el traspaso interno es fuente interna (modalidad transfer, nunca recojo en tienda)', function (): void {
    expect(OrderSource::Transfer->isInternalTransfer())->toBeTrue()
        ->and(OrderSource::Magento->isInternalTransfer())->toBeFalse()
        ->and(OrderSource::Sap->isInternalTransfer())->toBeFalse()
        ->and(OrderSource::internalTransferValues())->toBe(['TRANSFER'])
        ->and(DeliveryMode::from('transfer'))->toBe(DeliveryMode::Transfer);
});
