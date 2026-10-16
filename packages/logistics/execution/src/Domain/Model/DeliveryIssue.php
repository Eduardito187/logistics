<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use DateTimeImmutable;
use Logistics\Execution\Domain\Event\DeliveryIssueReported;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeliveryIssueId;
use Logistics\Execution\Domain\ValueObject\DeliveryIssueType;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Inconveniente reportado por el chofer durante una entrega EN CURSO: un HECHO inmutable (append-only).
 *
 * No cierra la entrega ni muta estado de negocio: solo deja constancia del obstáculo y graba el evento de
 * dominio que la torre de control y las notificaciones consumen por el outbox. Referencia al envío por id
 * string: no se acopla a shipments. Lleva el tipo, una nota opcional, el GPS del chofer y la foto opcional.
 */
final class DeliveryIssue extends AggregateRoot
{
    private function __construct(
        private readonly DeliveryIssueId $id,
        private readonly string $shipmentId,
        private readonly DeliveryIssueType $type,
        private readonly ?string $note,
        private readonly ?GeoPoint $location,
        private readonly ?string $photoPath,
        private readonly DeviceContext $reportedBy,
        private readonly DateTimeImmutable $occurredAt,
        private readonly ?string $idempotencyKey,
    ) {}

    /**
     * Registra el inconveniente y graba el evento de dominio que dispara los avisos aguas abajo.
     */
    public static function report(
        DeliveryIssueId $id,
        string $shipmentId,
        DeliveryIssueType $type,
        ?string $note,
        ?GeoPoint $location,
        ?string $photoPath,
        DeviceContext $reportedBy,
        DateTimeImmutable $occurredAt,
        ?string $idempotencyKey = null,
    ): self {
        $shipment = trim($shipmentId);
        if ($shipment === '') {
            throw ExecutionException::because('el inconveniente de entrega requiere el id del envío');
        }

        $issue = new self(
            $id,
            $shipment,
            $type,
            self::clean($note),
            $location,
            self::clean($photoPath),
            $reportedBy,
            $occurredAt,
            $idempotencyKey,
        );

        $issue->recordEvent(new DeliveryIssueReported(
            $id->toString(),
            $shipment,
            $type->value,
            $issue->note,
            $location?->lat,
            $location?->lng,
            $issue->photoPath,
            $occurredAt,
        ));

        return $issue;
    }

    public function id(): DeliveryIssueId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function type(): DeliveryIssueType
    {
        return $this->type;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function location(): ?GeoPoint
    {
        return $this->location;
    }

    public function photoPath(): ?string
    {
        return $this->photoPath;
    }

    public function reportedBy(): DeviceContext
    {
        return $this->reportedBy;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * Normaliza un texto opcional: recorta espacios y convierte el vacío en null.
     */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
