<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se emitió el documento fiscal de una factura: el sistema tributario le asignó número.
 *
 * Es el hecho de negocio publicable del contexto. Vía outbox puede reflejarse en SAP por el conector
 * (ADR 002: nunca HTTP síncrono) o alimentar notificaciones al cliente. Su payload lleva el número
 * fiscal y los totales para no volver a consultar a invoicing aguas abajo.
 */
final readonly class InvoiceIssued extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $orderId,
        public string $number,
        public int $subtotalCents,
        public int $taxCents,
        public int $totalCents,
        public string $currency,
        public string $issuedAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'invoicing.invoice_issued';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'number' => $this->number,
            'subtotal_cents' => $this->subtotalCents,
            'tax_cents' => $this->taxCents,
            'total_cents' => $this->totalCents,
            'currency' => $this->currency,
            'issued_at' => $this->issuedAt,
        ];
    }
}
