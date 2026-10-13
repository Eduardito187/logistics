<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;

/**
 * Proyección de lectura de un vehículo para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class VehicleView
{
    /**
     * @param list<array{type: string, expires_on: string}> $documents
     */
    private function __construct(
        public string $id,
        public string $plate,
        public int $capacityKg,
        public float $capacityM3,
        public string $homeBaseNodeId,
        public string $status,
        public array $documents,
    ) {}

    public static function fromDomain(Vehicle $vehicle): self
    {
        return new self(
            $vehicle->id()->toString(),
            $vehicle->plate()->value,
            $vehicle->capacity()->kilograms,
            $vehicle->capacity()->cubicMeters,
            $vehicle->homeBaseNodeId(),
            $vehicle->status()->value,
            array_values(array_map(static fn (DocumentExpiry $document) => $document->toArray(), $vehicle->documents())),
        );
    }

    /**
     * @return array{id: string, plate: string, capacity_kg: int, capacity_m3: float, home_base_node_id: string, status: string, documents: list<array{type: string, expires_on: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'capacity_kg' => $this->capacityKg,
            'capacity_m3' => $this->capacityM3,
            'home_base_node_id' => $this->homeBaseNodeId,
            'status' => $this->status,
            'documents' => $this->documents,
        ];
    }
}
