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
     * @param list<string>                                  $unfitItems
     */
    private function __construct(
        public string $id,
        public string $plate,
        public int $capacityKg,
        public float $capacityM3,
        public string $homeBaseNodeId,
        public string $status,
        public array $documents,
        public bool $fitToDispatch = true,
        public ?string $unfitSince = null,
        public ?string $unfitReason = null,
        public array $unfitItems = [],
    ) {}

    public static function fromDomain(Vehicle $vehicle): self
    {
        $fitness = $vehicle->fitness();

        return new self(
            $vehicle->id()->toString(),
            $vehicle->plate()->value,
            $vehicle->capacity()->kilograms,
            $vehicle->capacity()->cubicMeters,
            $vehicle->homeBaseNodeId(),
            $vehicle->status()->value,
            array_values(array_map(static fn (DocumentExpiry $document) => $document->toArray(), $vehicle->documents())),
            !$fitness->isUnfit(),
            $fitness->unfitSince?->format('Y-m-d'),
            $fitness->reason,
            $fitness->failedItems,
        );
    }

    /**
     * @return array{id: string, plate: string, capacity_kg: int, capacity_m3: float, home_base_node_id: string, status: string, documents: list<array{type: string, expires_on: string}>, fit_to_dispatch: bool, unfit_since: string|null, unfit_reason: string|null, unfit_items: list<string>}
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
            // Aditivo (#27): aptitud para despachar (false = bloqueado por checklist crítico hasta habilitarlo).
            'fit_to_dispatch' => $this->fitToDispatch,
            'unfit_since' => $this->unfitSince,
            'unfit_reason' => $this->unfitReason,
            'unfit_items' => $this->unfitItems,
        ];
    }
}
