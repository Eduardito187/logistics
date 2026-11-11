<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Model;

use Commerce\Orders\Domain\Event\OrderCancelled;
use Commerce\Orders\Domain\Event\OrderContractSigned;
use Commerce\Orders\Domain\Event\OrderFulfillmentStarted;
use Commerce\Orders\Domain\Event\OrderInitialPaid;
use Commerce\Orders\Domain\Event\OrderPaid;
use Commerce\Orders\Domain\Event\OrderPlaced;
use Commerce\Orders\Domain\Exception\OrdersException;
use Commerce\Orders\Domain\ValueObject\FinancingPlan;
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
        private readonly FinancingPlan $financing,
        private bool $contractSigned,
        private bool $initialPaid,
    ) {}

    /**
     * Crea una orden en checkout (estado pendiente de pago). Requiere al menos una línea. El
     * financiamiento (contado por defecto) determina qué habilita el paso a procesando.
     *
     * @param list<OrderLine> $lines
     */
    public static function place(
        OrderId $id,
        ?Uuid $customerId,
        ShippingAddress $shipTo,
        array $lines,
        string $currency = 'BOB',
        ?string $cartId = null,
        FinancingPlan $financing = FinancingPlan::Cash,
    ): self {
        if ($lines === []) {
            throw OrdersException::because('No se puede crear una orden sin líneas.');
        }

        $order = new self($id, $customerId, OrderStatus::Pending, $currency, $shipTo, $lines, false, $financing, false, false);
        $order->recordEvent(new OrderPlaced(
            $id->toString(),
            $customerId?->value,
            $currency,
            $order->totalCents(),
            count($lines),
            $cartId,
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
        FinancingPlan $financing = FinancingPlan::Cash,
        bool $contractSigned = false,
        bool $initialPaid = false,
    ): self {
        return new self($id, $customerId, $status, $currency, $shipTo, $lines, $shipmentRequested, $financing, $contractSigned, $initialPaid);
    }

    /**
     * Confirma el pago (CONTADO). Idempotente: si ya está pagada o en fulfillment, no re-graba. No se puede
     * pagar una orden cancelada. En órdenes a CRÉDITO es no-op: no se cobran en full y su paso a procesando
     * lo gobiernan la firma del contrato y el pago del inicial (ver signContract/recordInitialPayment).
     */
    public function markPaid(string $gatewayRef): void
    {
        if ($this->status === OrderStatus::Cancelled) {
            throw OrdersException::because('No se puede pagar una orden cancelada.');
        }
        if ($this->financing->isCredit()) {
            return;
        }

        $this->promote($gatewayRef);
    }

    /**
     * Reporta la FIRMA DEL CONTRATO de crédito (hecho del POS/sistema de crédito). Solo aplica a órdenes a
     * crédito. Idempotente. Tras firmar, promueve a procesando si ya se cumplen las condiciones del plan.
     */
    public function signContract(?string $reference = null): void
    {
        $this->guardCreditAction();
        if ($this->contractSigned) {
            return;
        }

        $this->contractSigned = true;
        $this->recordEvent(new OrderContractSigned($this->id->toString(), $reference));

        $this->promoteIfReady($reference);
    }

    /**
     * Reporta el PAGO DE LA CUOTA INICIAL (hecho del POS/sistema de crédito). Solo aplica a crédito con
     * inicial. Idempotente. Tras registrarlo, promueve a procesando si ya se cumplen las condiciones.
     */
    public function recordInitialPayment(?string $reference = null): void
    {
        $this->guardCreditAction();
        if (!$this->financing->requiresInitial()) {
            throw OrdersException::because('Esta orden de crédito no lleva cuota inicial.');
        }
        if ($this->initialPaid) {
            return;
        }

        $this->initialPaid = true;
        $this->recordEvent(new OrderInitialPaid($this->id->toString(), $reference));

        $this->promoteIfReady($reference);
    }

    /** Las acciones de crédito solo aplican a órdenes a crédito y no cerradas. */
    private function guardCreditAction(): void
    {
        if ($this->status === OrderStatus::Cancelled) {
            throw OrdersException::because('No se puede operar el crédito de una orden cancelada.');
        }
        if (!$this->financing->isCredit()) {
            throw OrdersException::because('Esta orden no es a crédito.');
        }
    }

    /**
     * Promueve a procesando si la orden está pendiente y se cumplen las condiciones del financiamiento:
     * crédito sin inicial → contrato firmado; crédito con inicial → contrato firmado Y inicial pagado.
     */
    private function promoteIfReady(?string $reference): void
    {
        if ($this->status !== OrderStatus::Pending) {
            return;
        }

        $ready = $this->financing->requiresInitial()
            ? $this->contractSigned && $this->initialPaid
            : $this->contractSigned;

        if ($ready) {
            $this->promote($reference ?? 'credit');
        }
    }

    /** Pasa la orden a Paid y emite OrderPaid (el hecho que dispara el fulfillment). Idempotente. */
    private function promote(string $ref): void
    {
        if ($this->status === OrderStatus::Paid || $this->status === OrderStatus::Fulfilling) {
            return;
        }

        $this->status = OrderStatus::Paid;
        $this->recordEvent(new OrderPaid(
            $this->id->toString(),
            $ref,
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
     * Cancela la orden. Se permite en cualquier estado no terminal: Pending, Paid o Fulfilling (ADR 020).
     * Idempotente: si ya está cancelada, no re-graba. `was_paid` (estado previo Paid/Fulfilling) viaja en
     * el evento para que los reactores cruzados decidan reembolso y logística inversa.
     *
     * @param string|null $reason motivo opcional (p. ej. `unpaid_timeout` en la expiración por cron)
     * @param string      $source origen: `pos`, `manual` (admin), `cron` (expiración), `customer`
     */
    public function cancel(?string $reason = null, string $source = 'manual'): void
    {
        if ($this->status === OrderStatus::Cancelled) {
            return;
        }
        if (!in_array($this->status, [OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Fulfilling], true)) {
            throw OrdersException::because('No se puede cancelar una orden en un estado terminal.');
        }

        $wasPaid = $this->status === OrderStatus::Paid || $this->status === OrderStatus::Fulfilling;

        $this->status = OrderStatus::Cancelled;
        $this->recordEvent(new OrderCancelled($this->id->toString(), $wasPaid, $reason, $source));
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

    public function financing(): FinancingPlan
    {
        return $this->financing;
    }

    public function contractSigned(): bool
    {
        return $this->contractSigned;
    }

    public function initialPaid(): bool
    {
        return $this->initialPaid;
    }
}
