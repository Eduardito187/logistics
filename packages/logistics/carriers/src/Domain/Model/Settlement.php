<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Model;

use Logistics\Carriers\Domain\Event\SettlementApproved;
use Logistics\Carriers\Domain\Event\SettlementDisputed;
use Logistics\Carriers\Domain\Event\SettlementDocumentAttached;
use Logistics\Carriers\Domain\Event\SettlementPaid;
use Logistics\Carriers\Domain\Event\SettlementSubmitted;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\BillingPeriod;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\Money;
use Logistics\Carriers\Domain\ValueObject\SettlementId;
use Logistics\Carriers\Domain\ValueObject\SettlementStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Liquidación de lo que se le debe a un transportista por el servicio prestado en un corredor durante
 * un período (P2-CAR-04).
 *
 * Ciclo de vida: el transportista la envía (Submitted); la operación la aprueba (Approved) y luego la
 * marca pagada (Paid), o la objeta (Disputed) si hay una discrepancia. Desde Disputed se puede volver a
 * enviar/aprobar. El agregado protege las transiciones válidas y acumula sus documentos (factura,
 * comprobantes). Referencia al transportista por id como texto para no acoplarse al agregado Carrier.
 */
final class Settlement extends AggregateRoot
{
    /**
     * @param list<array{type: string, reference: string}> $documents
     */
    private function __construct(
        private readonly SettlementId $id,
        private readonly string $carrierId,
        private readonly CorridorRef $corridor,
        private readonly BillingPeriod $period,
        private readonly Money $amount,
        private readonly string $reference,
        private SettlementStatus $status,
        private ?string $disputeReason,
        private ?string $paymentReference,
        private array $documents,
    ) {}

    /**
     * El transportista envía una liquidación nueva. Arranca en Submitted.
     *
     * @param list<array{type: string, reference: string}> $documents
     */
    public static function submit(
        SettlementId $id,
        string $carrierId,
        CorridorRef $corridor,
        BillingPeriod $period,
        Money $amount,
        string $reference,
        array $documents = [],
    ): self {
        $carrierId = trim($carrierId);
        if ($carrierId === '') {
            throw CarriersException::because('La liquidación requiere un transportista.');
        }
        $reference = trim($reference);
        if ($reference === '') {
            throw CarriersException::because('La liquidación requiere una referencia (p. ej. número de factura).');
        }
        if ($amount->cents <= 0) {
            throw CarriersException::because('El monto de la liquidación debe ser mayor que cero.');
        }

        $settlement = new self(
            $id,
            $carrierId,
            $corridor,
            $period,
            $amount,
            $reference,
            SettlementStatus::Submitted,
            null,
            null,
            array_values($documents),
        );
        $settlement->recordEvent(new SettlementSubmitted(
            $id->toString(),
            $carrierId,
            $corridor->from,
            $corridor->to,
            $corridor->type,
            $period->toString(),
            $amount->cents,
            $amount->currency,
            $reference,
        ));

        return $settlement;
    }

    /**
     * Reconstituye una liquidación desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<array{type: string, reference: string}> $documents
     */
    public static function reconstitute(
        SettlementId $id,
        string $carrierId,
        CorridorRef $corridor,
        BillingPeriod $period,
        Money $amount,
        string $reference,
        SettlementStatus $status,
        ?string $disputeReason,
        ?string $paymentReference,
        array $documents,
    ): self {
        return new self(
            $id,
            $carrierId,
            $corridor,
            $period,
            $amount,
            $reference,
            $status,
            $disputeReason,
            $paymentReference,
            array_values($documents),
        );
    }

    /**
     * Aprueba la liquidación. Solo desde Submitted o Disputed.
     */
    public function approve(): void
    {
        if (!in_array($this->status, [SettlementStatus::Submitted, SettlementStatus::Disputed], true)) {
            throw CarriersException::because(
                "No se puede aprobar una liquidación en estado {$this->status->value}.",
            );
        }
        $this->status = SettlementStatus::Approved;
        $this->disputeReason = null;
        $this->recordEvent(new SettlementApproved($this->id->toString()));
    }

    /**
     * Marca la liquidación como pagada. Solo desde Approved.
     */
    public function markPaid(string $paymentReference): void
    {
        if ($this->status !== SettlementStatus::Approved) {
            throw CarriersException::because(
                "Solo se puede pagar una liquidación aprobada; está en {$this->status->value}.",
            );
        }
        $paymentReference = trim($paymentReference);
        if ($paymentReference === '') {
            throw CarriersException::because('El pago requiere una referencia (p. ej. número de transferencia).');
        }
        $this->status = SettlementStatus::Paid;
        $this->paymentReference = $paymentReference;
        $this->recordEvent(new SettlementPaid($this->id->toString(), $paymentReference));
    }

    /**
     * Objeta la liquidación por una discrepancia. No se puede objetar una ya pagada.
     */
    public function dispute(string $reason): void
    {
        if ($this->status === SettlementStatus::Paid) {
            throw CarriersException::because('No se puede objetar una liquidación ya pagada.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw CarriersException::because('La objeción requiere un motivo.');
        }
        $this->status = SettlementStatus::Disputed;
        $this->disputeReason = $reason;
        $this->recordEvent(new SettlementDisputed($this->id->toString(), $reason));
    }

    /**
     * Adjunta un documento a la liquidación. No se admiten cambios en una ya pagada.
     *
     * @param array{type: string, reference: string} $document
     */
    public function attachDocument(array $document): void
    {
        if ($this->status === SettlementStatus::Paid) {
            throw CarriersException::because('No se pueden adjuntar documentos a una liquidación ya pagada.');
        }
        $type = trim($document['type']);
        $reference = trim($document['reference']);
        if ($type === '' || $reference === '') {
            throw CarriersException::because('El documento requiere tipo y referencia.');
        }
        $this->documents[] = ['type' => $type, 'reference' => $reference];
        $this->recordEvent(new SettlementDocumentAttached($this->id->toString(), $type, $reference));
    }

    public function id(): SettlementId
    {
        return $this->id;
    }

    public function carrierId(): string
    {
        return $this->carrierId;
    }

    public function corridor(): CorridorRef
    {
        return $this->corridor;
    }

    public function period(): BillingPeriod
    {
        return $this->period;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function status(): SettlementStatus
    {
        return $this->status;
    }

    public function disputeReason(): ?string
    {
        return $this->disputeReason;
    }

    public function paymentReference(): ?string
    {
        return $this->paymentReference;
    }

    /**
     * @return list<array{type: string, reference: string}>
     */
    public function documents(): array
    {
        return $this->documents;
    }
}
