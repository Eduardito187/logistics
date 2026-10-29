<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Model;

use Commerce\Orders\Domain\Event\OrderCancelled;
use Commerce\Orders\Domain\Event\OrderFulfillmentStarted;
use Commerce\Orders\Domain\Event\OrderPaid;
use Commerce\Orders\Domain\Event\OrderPlaced;
use Commerce\Orders\Domain\Exception\OrdersException;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Commerce\Orders\Domain\ValueObject\OrderLine;
use Commerce\Orders\Domain\ValueObject\OrderStatus;
use Commerce\Orders\Domain\ValueObject\ShippingAddress;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Orden de compra comercial (OMS) — el punto de convergencia comercio ↔ logística (ADR 018).
 *
 * Nace en el checkout con una instantánea de líneas (precios congelados) y los datos de entrega.
 * Cuando el pago se confirma (evento `payments.captured` → comando MarkOrderPaid) pasa a Paid y emite
 * `orders.order_paid`; un consumidor en Infrastructure crea el envío reutilizando `shipments`
 * (idempotente por el id de la orden) y la orden pasa a Fulfilling. El dominio no conoce `shipments`.
 */
final class Order extends AggregateRoot
{
    /**
     * @param list<OrderLine> $lines
     */
    private function __construct(
        private readonly OrderId $id,
        private readonly ?Uuid $customerId,
        private OrderStatus $status,
        private readonly string $currency,
        private readonly ShippingAddress $shipTo,
        private readonly array $lines,
        private bool $shipmentRequested,
    ) {}

    /**
     * Crea una orden en checkout (estado pendiente de pago). Requiere al menos una línea.
     *
     * @param list<OrderLine> $lines
     */
    public static function place(
        OrderId $id,
        ?Uuid $customerId,
        ShippingAddress $shipTo,
        array $lines,
        string $currency = 'BOB',
    ): self {
        if ($lines === []) {
            throw OrdersException::because('No se puede crear una orden sin líneas.');
        }

        $order = new self($id, $customerId, OrderStatus::Pending, $currency, $shipTo, $lines, false);
        $order->recordEvent(new OrderPlaced(
            $id->toString(),
            $customerId?->value,
            $currency,
            $order->totalCents(),
            count($lines),
        ));

        return $order;
    }

    /**
     * Reconstituye una orden desde persistencia, sin grabar eventos.
     *
     * @param list<OrderLine> $lines
     */
    public static function reconstitute(
        OrderId $id,
        ?Uuid $customerId,
        OrderStatus $status,
        string $currency,
        ShippingAddress $shipTo,
        array $lines,
        bool $shipmentRequested,
    ): self {
        return new self($id, $customerId, $status, $currency, $shipTo, $lines, $shipmentRequested);
    }

    /**
     * Confirma el pago. Idempotente: si ya está pagada o en fulfillment, no re-graba. No se puede pagar
     * una orden cancelada.
     */
    public function markPaid(string $gatewayRef): void
    {
        if ($this->status === OrderStatus::Paid || $this->status === OrderStatus::Fulfilling) {
            return;
        }
        if ($this->status === OrderStatus::Cancelled) {
            throw OrdersException::because('No se puede pagar una orden cancelada.');
        }

        $this->status = OrderStatus::Paid;
        $this->recordEvent(new OrderPaid(
            $this->id->toString(),
            $gatewayRef,
            $this->totalCents(),
            $this->currency,
        ));
    }

    /**
     * Marca que el fulfillment arrancó (se solicitó el envío). Idempotente por `shipmentRequested`.
     * Solo válido sobre una orden pagada.
     */
    public function markFulfilling(): void
    {
        if ($this->shipmentRequested) {
            return;
        }
        if ($this->status !== OrderStatus::Paid) {
            throw OrdersException::because('Solo una orden pagada puede entrar en fulfillment.');
        }

        $this->status = OrderStatus::Fulfilling;
        $this->shipmentRequested = true;
        $this->recordEvent(new OrderFulfillmentStarted($this->id->toString()));
    }

    /**
     * Cancela la orden. Solo posible mientras está pendiente de pago.
     */
    public function cancel(): void
    {
        if ($this->status === OrderStatus::Cancelled) {
            return;
        }
        if ($this->status !== OrderStatus::Pending) {
            throw OrdersException::because('Solo se puede cancelar una orden pendiente de pago.');
        }

        $this->status = OrderStatus::Cancelled;
        $this->recordEvent(new OrderCancelled($this->id->toString()));
    }

    public function totalCents(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $total += $line->subtotalCents();
        }

        return $total;
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function customerId(): ?Uuid
    {
        return $this->customerId;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function shipTo(): ShippingAddress
    {
        return $this->shipTo;
    }

    /**
     * @return list<OrderLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function shipmentRequested(): bool
    {
        return $this->shipmentRequested;
    }
}
