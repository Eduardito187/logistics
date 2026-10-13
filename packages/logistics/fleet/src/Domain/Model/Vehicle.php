<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Event\VehicleRegistered;
use Logistics\Fleet\Domain\Event\VehicleStatusChanged;
use Logistics\Fleet\Domain\ValueObject\Capacity;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\PlateNumber;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Vehículo de la flota: placa, capacidad, CD base, estado operativo y documentos con vencimiento.
 *
 * No sabe qué carga lleva ni a dónde va (eso es de planning/execution); solo su identidad, su aptitud
 * para operar y el vencimiento de sus documentos. `homeBaseNodeId` referencia a un nodo de network por
 * su id como texto, a propósito, para no acoplar este contexto al package network.
 */
final class Vehicle extends AggregateRoot
{
    /**
     * @param list<DocumentExpiry> $documents
     */
    private function __construct(
        private readonly VehicleId $id,
        private readonly PlateNumber $plate,
        private Capacity $capacity,
        private readonly string $homeBaseNodeId,
        private VehicleStatus $status,
        private array $documents,
    ) {}

    /**
     * Registra un vehículo nuevo. Arranca disponible salvo que se indique otro estado.
     *
     * @param list<DocumentExpiry> $documents
     */
    public static function register(
        VehicleId $id,
        PlateNumber $plate,
        Capacity $capacity,
        string $homeBaseNodeId,
        array $documents = [],
        VehicleStatus $status = VehicleStatus::Available,
    ): self {
        $vehicle = new self($id, $plate, $capacity, $homeBaseNodeId, $status, array_values($documents));
        $vehicle->recordEvent(new VehicleRegistered(
            $id->toString(),
            $plate->value,
            $capacity->kilograms,
            $capacity->cubicMeters,
            $homeBaseNodeId,
            $status->value,
        ));

        return $vehicle;
    }

    /**
     * Reconstituye un vehículo desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<DocumentExpiry> $documents
     */
    public static function reconstitute(
        VehicleId $id,
        PlateNumber $plate,
        Capacity $capacity,
        string $homeBaseNodeId,
        VehicleStatus $status,
        array $documents,
    ): self {
        return new self($id, $plate, $capacity, $homeBaseNodeId, $status, array_values($documents));
    }

    public function sendToMaintenance(): void
    {
        $this->changeStatus(VehicleStatus::Maintenance);
    }

    public function returnToService(): void
    {
        $this->changeStatus(VehicleStatus::Available);
    }

    /**
     * Cambia el estado del vehículo. Idempotente: si ya está en ese estado, no hace nada ni graba evento.
     */
    public function changeStatus(VehicleStatus $new): void
    {
        if ($this->status === $new) {
            return;
        }
        $previous = $this->status;
        $this->status = $new;
        $this->recordEvent(new VehicleStatusChanged($this->id->toString(), $previous->value, $new->value));
    }

    public function addDocument(DocumentExpiry $document): void
    {
        $this->documents[] = $document;
    }

    /**
     * Documentos del vehículo que vencen dentro de $days días contados desde $ref (incluye vencidos).
     *
     * @return list<DocumentExpiry>
     */
    public function documentsExpiringWithin(int $days, DateTimeImmutable $ref): array
    {
        return array_values(array_filter(
            $this->documents,
            static fn (DocumentExpiry $document): bool => $document->expiresWithin($days, $ref),
        ));
    }

    public function id(): VehicleId
    {
        return $this->id;
    }

    public function plate(): PlateNumber
    {
        return $this->plate;
    }

    public function capacity(): Capacity
    {
        return $this->capacity;
    }

    public function homeBaseNodeId(): string
    {
        return $this->homeBaseNodeId;
    }

    public function status(): VehicleStatus
    {
        return $this->status;
    }

    /**
     * @return list<DocumentExpiry>
     */
    public function documents(): array
    {
        return $this->documents;
    }
}
