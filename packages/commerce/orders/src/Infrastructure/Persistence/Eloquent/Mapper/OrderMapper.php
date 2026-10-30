<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\Persistence\Eloquent\Mapper;

use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Commerce\Orders\Domain\ValueObject\OrderLine;
use Commerce\Orders\Domain\ValueObject\OrderStatus;
use Commerce\Orders\Domain\ValueObject\ShippingAddress;
use Commerce\Orders\Infrastructure\Persistence\Eloquent\Model\OrderRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Traduce entre el agregado Order (dominio) y su fila Eloquent.
 */
final class OrderMapper
{
    public function toDomain(OrderRecord $record): Order
    {
        return Order::reconstitute(
            OrderId::fromString($record->id),
            $record->customer_id !== null ? Uuid::fromString($record->customer_id) : null,
            OrderStatus::from($record->status),
            $record->currency,
            ShippingAddress::fromArray($record->ship_to),
            array_values(array_map(
                static fn (array $line): OrderLine => OrderLine::fromArray($line),
                $record->lines,
            )),
            $record->shipment_requested,
        );
    }

    /**
     * @return array{id: string, customer_id: ?string, status: string, currency: string, shipment_requested: bool, ship_to: array{customer_name: string, address_text: string, reference: ?string, phone: ?string, email: ?string}, lines: list<array{sku: string, product_name: string, unit_price_cents: int, quantity: int}>}
     */
    public function toRow(Order $order): array
    {
        return [
            'id' => $order->id()->toString(),
            'customer_id' => $order->customerId()?->value,
            'status' => $order->status()->value,
            'currency' => $order->currency(),
            'shipment_requested' => $order->shipmentRequested(),
            'ship_to' => $order->shipTo()->toArray(),
            'lines' => array_values(array_map(
                static fn (OrderLine $line): array => $line->toArray(),
                $order->lines(),
            )),
        ];
    }
}
