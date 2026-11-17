<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Model;

use DateTimeImmutable;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\HaulageMode;
use Logistics\Shipments\Domain\ValueObject\LegStatus;
use Logistics\Shipments\Domain\ValueObject\ShipmentLegId;

/**
 * Un TRAMO del recorrido de un envío (modelo hub-and-spoke): el salto entre dos puntos de la red
 * (p. ej. almacén SCZ → almacén Tarija, o almacén Tarija → cliente). Un envío puede recorrer varios
 * tramos ordenados por `seq`; cada tramo lo mueve la flota propia o un tercero ({@see HaulageMode}).
 *
 * Entidad del agregado {@see ShipmentItinerary}: tiene identidad propia pero no vive fuera del itinerario
 * que la contiene. Es PHP puro (sin Laravel, sin Eloquent).
 *
 * CIMIENTO: solo guarda los datos del tramo. La lógica de ruteo (cómo se arma la red de tramos) y la
 * decisión de propio vs. tercerizado son de olas posteriores; esta entidad no las conoce.
 */
final class ShipmentLeg
{
    private function __construct(
        private readonly ShipmentLegId $id,
        private readonly int $seq,
        private readonly ?string $fromNodeId,
        private readonly ?string $toNodeId,
        private readonly ?string $fromDepartment,
        private readonly ?string $toDepartment,
        private readonly HaulageMode $haulageMode,
        private readonly ?string $carrierId,
        private readonly LegStatus $status,
        private readonly ?DateTimeImmutable $departedAt,
        private readonly ?DateTimeImmutable $arrivedAt,
    ) {}

    /**
     * Crea un tramo. `seq` es su posición en el itinerario (1 = primer tramo). El modo por defecto es la
     * flota propia y el estado por defecto, pendiente (el flujo que no decide todavía arranca así).
     */
    public static function create(
        ShipmentLegId $id,
        int $seq,
        ?string $fromNodeId = null,
        ?string $toNodeId = null,
        ?string $fromDepartment = null,
        ?string $toDepartment = null,
        HaulageMode $haulageMode = HaulageMode::OwnFleet,
        ?string $carrierId = null,
        LegStatus $status = LegStatus::Pending,
        ?DateTimeImmutable $departedAt = null,
        ?DateTimeImmutable $arrivedAt = null,
    ): self {
        if ($seq < 1) {
            throw ShipmentsException::because("la secuencia del tramo debe ser >= 1: {$seq}");
        }

        return new self(
            $id,
            $seq,
            self::idOrNull($fromNodeId),
            self::idOrNull($toNodeId),
            self::textOrNull($fromDepartment),
            self::textOrNull($toDepartment),
            $haulageMode,
            self::idOrNull($carrierId),
            $status,
            $departedAt,
            $arrivedAt,
        );
    }

    public function id(): ShipmentLegId
    {
        return $this->id;
    }

    public function seq(): int
    {
        return $this->seq;
    }

    /** Nodo de ORIGEN del tramo (UUID de un nodo de red), o null si no se informó. */
    public function fromNodeId(): ?string
    {
        return $this->fromNodeId;
    }

    /** Nodo de DESTINO del tramo (UUID de un nodo de red), o null (p. ej. el último tramo va al cliente). */
    public function toNodeId(): ?string
    {
        return $this->toNodeId;
    }

    public function fromDepartment(): ?string
    {
        return $this->fromDepartment;
    }

    public function toDepartment(): ?string
    {
        return $this->toDepartment;
    }

    public function haulageMode(): HaulageMode
    {
        return $this->haulageMode;
    }

    /** Transportadora del tramo (UUID) cuando lo mueve un tercero, o null si es flota propia. */
    public function carrierId(): ?string
    {
        return $this->carrierId;
    }

    public function status(): LegStatus
    {
        return $this->status;
    }

    public function departedAt(): ?DateTimeImmutable
    {
        return $this->departedAt;
    }

    public function arrivedAt(): ?DateTimeImmutable
    {
        return $this->arrivedAt;
    }

    /** Id normalizado (sin espacios, en minúsculas), o null si viene vacío. */
    private static function idOrNull(?string $value): ?string
    {
        $trimmed = $value !== null ? strtolower(trim($value)) : '';

        return $trimmed === '' ? null : $trimmed;
    }

    /** Texto no vacío (sin espacios al borde), o null. */
    private static function textOrNull(?string $value): ?string
    {
        $trimmed = $value !== null ? trim($value) : '';

        return $trimmed === '' ? null : $trimmed;
    }
}
