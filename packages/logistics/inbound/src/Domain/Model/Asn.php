<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Model;

use Logistics\Inbound\Domain\Event\AsnAnnounced;
use Logistics\Inbound\Domain\Event\AsnCancelled;
use Logistics\Inbound\Domain\Event\AsnClosed;
use Logistics\Inbound\Domain\Event\AsnScheduled;
use Logistics\Inbound\Domain\Event\ShipmentArrived;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Domain\ValueObject\AsnLine;
use Logistics\Inbound\Domain\ValueObject\AsnStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * ASN (Advanced Shipping Notice) / cita de recepción de un seller en un almacén central (ADR 017, Fase 2).
 *
 * Custodia el ciclo de vida de la cita y las invariantes de transición: Announced → Scheduled → Arrived →
 * Receiving → Received → Closed, o Cancelled desde cualquier estado no terminal. La recepción física con
 * QC NO vive aquí: es el agregado GoodsReceipt (una recepción puede tener su propio id e historia). El
 * dueño del producto es el seller; la unidad entra como FBD (Fulfilled by Dismac) y converge con la
 * plataforma retail recién al etiquetarse.
 *
 * El seller y el almacén se guardan como id en texto (UUID de otros contextos) para no acoplar inbound a
 * sellers ni a network: la referencia entre contextos es por id, no por objeto de dominio.
 */
final class Asn extends AggregateRoot
{
    /**
     * @param list<AsnLine> $lines
     */
    private function __construct(
        private readonly AsnId $id,
        private readonly string $sellerId,
        private readonly string $warehouseId,
        private array $lines,
        private AsnStatus $status,
        private ?AppointmentWindow $appointmentWindow,
    ) {}

    /**
     * Un seller anuncia un ASN. Arranca en Announced, sin cita. Debe traer al menos una línea.
     *
     * @param list<AsnLine> $lines
     */
    public static function announce(
        AsnId $id,
        string $sellerId,
        string $warehouseId,
        array $lines,
    ): self {
        $sellerId = trim($sellerId);
        if ($sellerId === '') {
            throw InboundException::because('El ASN debe indicar el seller dueño del producto.');
        }
        $warehouseId = trim($warehouseId);
        if ($warehouseId === '') {
            throw InboundException::because('El ASN debe indicar el almacén central destino.');
        }
        if ($lines === []) {
            throw InboundException::because('Un ASN debe tener al menos una línea (sku + cantidad esperada).');
        }

        $asn = new self($id, $sellerId, $warehouseId, array_values($lines), AsnStatus::Announced, null);
        $asn->recordEvent(new AsnAnnounced(
            $id->toString(),
            $sellerId,
            $warehouseId,
            array_map(static fn (AsnLine $line): array => $line->toArray(), array_values($lines)),
        ));

        return $asn;
    }

    /**
     * Reconstituye un ASN desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<AsnLine> $lines
     */
    public static function reconstitute(
        AsnId $id,
        string $sellerId,
        string $warehouseId,
        array $lines,
        AsnStatus $status,
        ?AppointmentWindow $appointmentWindow,
    ): self {
        return new self($id, $sellerId, $warehouseId, array_values($lines), $status, $appointmentWindow);
    }

    /**
     * Agenda (o reagenda) la cita de recepción. Solo desde Announced o Scheduled. Reserva de cupo de andén
     * la hace el handler (puerto DockQuotaReserver), no el agregado.
     */
    public function schedule(AppointmentWindow $window): void
    {
        if ($this->status !== AsnStatus::Announced && $this->status !== AsnStatus::Scheduled) {
            throw InboundException::because("No se puede agendar un ASN en estado {$this->status->value}.");
        }

        $this->status = AsnStatus::Scheduled;
        $this->appointmentWindow = $window;
        $this->recordEvent(new AsnScheduled(
            $this->id->toString(),
            $this->warehouseId,
            $window->date,
            $window->timeSlot(),
        ));
    }

    /**
     * Registra la llegada del camión del seller. Solo desde Scheduled.
     */
    public function recordArrival(?string $carrierReference = null): void
    {
        if ($this->status !== AsnStatus::Scheduled) {
            throw InboundException::because("No se puede registrar la llegada de un ASN en estado {$this->status->value}.");
        }

        $this->status = AsnStatus::Arrived;
        $carrierReference = $carrierReference !== null ? trim($carrierReference) : null;
        $this->recordEvent(new ShipmentArrived(
            $this->id->toString(),
            $this->warehouseId,
            $carrierReference === '' ? null : $carrierReference,
        ));
    }

    /**
     * Marca que empieza la descarga/inspección. Idempotente si ya está en Receiving. Solo desde Arrived.
     * No emite evento: es una transición interna; el hecho de negocio es GoodsReceived (otro agregado).
     */
    public function startReceiving(): void
    {
        if ($this->status === AsnStatus::Receiving) {
            return;
        }
        if ($this->status !== AsnStatus::Arrived) {
            throw InboundException::because("No se puede iniciar la recepción de un ASN en estado {$this->status->value}.");
        }

        $this->status = AsnStatus::Receiving;
    }

    /**
     * Marca el ASN como recibido una vez registrado su goods receipt. Idempotente si ya está Received.
     * Admite desde Arrived (recepción directa) o Receiving. No emite evento (lo hace GoodsReceipt).
     */
    public function markReceived(): void
    {
        if ($this->status === AsnStatus::Received) {
            return;
        }
        if ($this->status !== AsnStatus::Arrived && $this->status !== AsnStatus::Receiving) {
            throw InboundException::because("No se puede recibir un ASN en estado {$this->status->value}.");
        }

        $this->status = AsnStatus::Received;
    }

    /**
     * Cierra el ASN (recepción conciliada y etiquetada). Solo desde Received. Idempotente si ya está Closed.
     */
    public function close(): void
    {
        if ($this->status === AsnStatus::Closed) {
            return;
        }
        if ($this->status !== AsnStatus::Received) {
            throw InboundException::because("No se puede cerrar un ASN en estado {$this->status->value}.");
        }

        $this->status = AsnStatus::Closed;
        $this->recordEvent(new AsnClosed($this->id->toString()));
    }

    /**
     * Cancela el ASN. Desde cualquier estado no terminal. Idempotente si ya está Cancelled.
     */
    public function cancel(?string $reason = null): void
    {
        if ($this->status === AsnStatus::Cancelled) {
            return;
        }
        if ($this->status->isTerminal()) {
            throw InboundException::because("No se puede cancelar un ASN en estado {$this->status->value}.");
        }

        $this->status = AsnStatus::Cancelled;
        $reason = $reason !== null ? trim($reason) : null;
        $this->recordEvent(new AsnCancelled($this->id->toString(), $reason === '' ? null : $reason));
    }

    public function id(): AsnId
    {
        return $this->id;
    }

    /**
     * El id del seller como Uuid (dueño del producto; alimenta la dimensión de propiedad FBD).
     */
    public function sellerUuid(): Uuid
    {
        return Uuid::fromString($this->sellerId);
    }

    public function sellerId(): string
    {
        return $this->sellerId;
    }

    public function warehouseId(): string
    {
        return $this->warehouseId;
    }

    /**
     * @return list<AsnLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function status(): AsnStatus
    {
        return $this->status;
    }

    public function appointmentWindow(): ?AppointmentWindow
    {
        return $this->appointmentWindow;
    }
}
