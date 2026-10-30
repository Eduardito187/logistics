<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\Model;

use Commerce\Invoicing\Domain\Event\InvoiceCancelled;
use Commerce\Invoicing\Domain\Event\InvoiceDrafted;
use Commerce\Invoicing\Domain\Event\InvoiceIssued;
use Commerce\Invoicing\Domain\Exception\InvoicingException;
use Commerce\Invoicing\Domain\ValueObject\BolivianTax;
use Commerce\Invoicing\Domain\ValueObject\InvoiceId;
use Commerce\Invoicing\Domain\ValueObject\InvoiceLine;
use Commerce\Invoicing\Domain\ValueObject\InvoiceStatus;
use DateTimeImmutable;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factura: documento fiscal de una orden pagada (EC-F0-10, ADR 018).
 *
 * Referencia la orden por un `Uuid` genérico (invoicing NO depende del contexto orders): el
 * acoplamiento se resuelve por evento/puerto, no por `use` cruzado entre familias. Custodia las
 * invariantes: se calcula desde las líneas (bruto = suma de totales de línea), separa neto e IVA con
 * `BolivianTax` (13 % por dentro) y emite los hechos de negocio al outbox. El número fiscal lo asigna el
 * sistema tributario a través del puerto `TaxDocumentGateway` y se guarda al emitir.
 *
 * @see BolivianTax para la regla de descomposición del IVA.
 */
final class Invoice extends AggregateRoot
{
    /**
     * @param list<InvoiceLine> $lines
     */
    private function __construct(
        private readonly InvoiceId $id,
        private readonly Uuid $orderId,
        private ?string $number,
        private readonly string $customerName,
        private readonly ?string $customerTaxId,
        private readonly string $currency,
        private readonly array $lines,
        private readonly int $subtotalCents,
        private readonly int $taxCents,
        private readonly int $totalCents,
        private InvoiceStatus $status,
        private ?DateTimeImmutable $issuedAt,
    ) {}

    /**
     * Crea la factura borrador de una orden calculando neto/IVA/total desde las líneas.
     *
     * El total bruto es la suma de los totales de línea; de ese bruto se separan neto e IVA (IVA 13 %
     * por dentro, `BolivianTax`). Arranca en Draft y graba `InvoiceDrafted`.
     *
     * @param list<InvoiceLine> $lines
     */
    public static function draft(
        InvoiceId $id,
        Uuid $orderId,
        string $customerName,
        ?string $customerTaxId,
        string $currency,
        array $lines,
    ): self {
        $customerName = trim($customerName);
        if ($customerName === '') {
            throw InvoicingException::because('El nombre del cliente no puede estar vacío.');
        }
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw InvoicingException::because('La moneda no puede estar vacía.');
        }
        if ($lines === []) {
            throw InvoicingException::because('Una factura debe tener al menos una línea.');
        }
        $customerTaxId = $customerTaxId !== null ? trim($customerTaxId) : null;
        if ($customerTaxId === '') {
            $customerTaxId = null;
        }

        $gross = 0;
        foreach ($lines as $line) {
            $gross += $line->lineTotalCents;
        }
        $tax = BolivianTax::of($gross);

        $invoice = new self(
            $id,
            $orderId,
            null,
            $customerName,
            $customerTaxId,
            $currency,
            array_values($lines),
            $tax->netCents,
            $tax->ivaCents,
            $gross,
            InvoiceStatus::Draft,
            null,
        );
        $invoice->recordEvent(new InvoiceDrafted(
            $id->toString(),
            $orderId->value,
            $tax->netCents,
            $tax->ivaCents,
            $gross,
            $currency,
        ));

        return $invoice;
    }

    /**
     * Reconstituye una factura desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<InvoiceLine> $lines
     */
    public static function reconstitute(
        InvoiceId $id,
        Uuid $orderId,
        ?string $number,
        string $customerName,
        ?string $customerTaxId,
        string $currency,
        array $lines,
        int $subtotalCents,
        int $taxCents,
        int $totalCents,
        InvoiceStatus $status,
        ?DateTimeImmutable $issuedAt,
    ): self {
        return new self(
            $id,
            $orderId,
            $number,
            $customerName,
            $customerTaxId,
            $currency,
            array_values($lines),
            $subtotalCents,
            $taxCents,
            $totalCents,
            $status,
            $issuedAt,
        );
    }

    /**
     * Emite el documento fiscal: fija el número asignado por el sistema tributario y pasa a Issued.
     *
     * Solo válido desde Draft → Issued. Idempotente: si ya está Issued no vuelve a grabar el evento (el
     * outbox reentrega at-least-once). Desde Cancelled es una transición inválida.
     */
    public function issue(string $number, Clock $clock): void
    {
        if ($this->status === InvoiceStatus::Issued) {
            return;
        }
        if ($this->status !== InvoiceStatus::Draft) {
            throw InvoicingException::because(
                "No se puede emitir una factura en estado {$this->status->value}.",
            );
        }
        $number = trim($number);
        if ($number === '') {
            throw InvoicingException::because('El número fiscal no puede estar vacío.');
        }

        $this->status = InvoiceStatus::Issued;
        $this->number = $number;
        $this->issuedAt = $clock->now();
        $this->recordEvent(new InvoiceIssued(
            $this->id->toString(),
            $this->orderId->value,
            $number,
            $this->subtotalCents,
            $this->taxCents,
            $this->totalCents,
            $this->currency,
            $this->issuedAt->format(DATE_ATOM),
        ));
    }

    /**
     * Anula la factura. Válido desde Draft o Issued → Cancelled. Graba `InvoiceCancelled`.
     *
     * Idempotente: si ya está Cancelled no vuelve a grabar.
     */
    public function cancel(): void
    {
        if ($this->status === InvoiceStatus::Cancelled) {
            return;
        }

        $this->status = InvoiceStatus::Cancelled;
        $this->recordEvent(new InvoiceCancelled(
            $this->id->toString(),
            $this->orderId->value,
        ));
    }

    public function id(): InvoiceId
    {
        return $this->id;
    }

    public function orderId(): Uuid
    {
        return $this->orderId;
    }

    public function number(): ?string
    {
        return $this->number;
    }

    public function customerName(): string
    {
        return $this->customerName;
    }

    public function customerTaxId(): ?string
    {
        return $this->customerTaxId;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * @return list<InvoiceLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function subtotalCents(): int
    {
        return $this->subtotalCents;
    }

    public function taxCents(): int
    {
        return $this->taxCents;
    }

    public function totalCents(): int
    {
        return $this->totalCents;
    }

    public function status(): InvoiceStatus
    {
        return $this->status;
    }

    public function issuedAt(): ?DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
