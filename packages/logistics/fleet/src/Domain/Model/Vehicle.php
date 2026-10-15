<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Event\VehicleProfileUpdated;
use Logistics\Fleet\Domain\Event\VehicleRegistered;
use Logistics\Fleet\Domain\Event\VehicleStatusChanged;
use Logistics\Fleet\Domain\ValueObject\Capacity;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\PlateNumber;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Domain\ValueObject\VehicleProfile;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Vehículo de la flota: placa, capacidad, CD base, estado operativo y documentos con vencimiento.
 *
 * No sabe qué carga lleva ni a dónde va (eso es de planning/execution); solo su identidad, su aptitud
 * para operar y el vencimiento de sus documentos. `homeBaseNodeId` referencia a un nodo de network por
 * su id como texto, a propósito, para no acoplar este contexto al package network.
 *
 * Además lleva una ficha técnica opcional (marca, modelo, año, VIN, nº de motor, color) y las fotos de
 * sus cuatro lados, que se completan por separado con `updateProfile()`. Son nullable porque la ficha se
 * enriquece de a poco y los vehículos ya registrados siguen válidos sin esos datos.
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
        private ?string $make = null,
        private ?string $model = null,
        private ?int $modelYear = null,
        private ?string $vin = null,
        private ?string $engineNumber = null,
        private ?string $color = null,
        private ?string $photoFront = null,
        private ?string $photoBack = null,
        private ?string $photoLeft = null,
        private ?string $photoRight = null,
    ) {}

    /**
     * Registra un vehículo nuevo. Arranca disponible salvo que se indique otro estado.
     *
     * La ficha técnica es opcional y se puede completar luego con `updateProfile()`.
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
        ?string $make = null,
        ?string $model = null,
        ?int $modelYear = null,
        ?string $vin = null,
        ?string $engineNumber = null,
        ?string $color = null,
        ?string $photoFront = null,
        ?string $photoBack = null,
        ?string $photoLeft = null,
        ?string $photoRight = null,
    ): self {
        return new self(
            $id,
            $plate,
            $capacity,
            $homeBaseNodeId,
            $status,
            array_values($documents),
            $make,
            $model,
            $modelYear,
            $vin,
            $engineNumber,
            $color,
            $photoFront,
            $photoBack,
            $photoLeft,
            $photoRight,
        );
    }

    /**
     * Reemplaza por completo la ficha técnica del vehículo y graba el evento de perfil actualizado.
     */
    public function updateProfile(VehicleProfile $profile): void
    {
        $this->make = $profile->make;
        $this->model = $profile->model;
        $this->modelYear = $profile->modelYear;
        $this->vin = $profile->vin;
        $this->engineNumber = $profile->engineNumber;
        $this->color = $profile->color;
        $this->photoFront = $profile->photoFront;
        $this->photoBack = $profile->photoBack;
        $this->photoLeft = $profile->photoLeft;
        $this->photoRight = $profile->photoRight;

        $this->recordEvent(new VehicleProfileUpdated($this->id->toString(), $profile->toArray()));
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

    public function make(): ?string
    {
        return $this->make;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function modelYear(): ?int
    {
        return $this->modelYear;
    }

    public function vin(): ?string
    {
        return $this->vin;
    }

    public function engineNumber(): ?string
    {
        return $this->engineNumber;
    }

    public function color(): ?string
    {
        return $this->color;
    }

    public function photoFront(): ?string
    {
        return $this->photoFront;
    }

    public function photoBack(): ?string
    {
        return $this->photoBack;
    }

    public function photoLeft(): ?string
    {
        return $this->photoLeft;
    }

    public function photoRight(): ?string
    {
        return $this->photoRight;
    }
}
