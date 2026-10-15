<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

use Logistics\Fleet\Domain\Model\PreTripInspection;

/**
 * Proyección de lectura de una inspección pre-salida para listados y API. Solo primitivos.
 */
final readonly class PreTripChecklistView
{
    /**
     * @param array<string, bool> $items
     * @param list<string>        $pending
     */
    private function __construct(
        public string $id,
        public string $vehicleId,
        public string $driverId,
        public array $items,
        public bool $passed,
        public array $pending,
        public string $performedOn,
        public ?string $notes,
    ) {}

    public static function fromDomain(PreTripInspection $inspection): self
    {
        $checklist = $inspection->checklist();

        return new self(
            $inspection->id()->toString(),
            $inspection->vehicleId()->toString(),
            $inspection->driverId()->toString(),
            $checklist->toArray(),
            $inspection->passed(),
            $checklist->pending(),
            $inspection->performedOn()->format('Y-m-d'),
            $inspection->notes(),
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string, items: array<string, bool>, passed: bool, pending: list<string>, performed_on: string, notes: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'driver_id' => $this->driverId,
            'items' => $this->items,
            'passed' => $this->passed,
            'pending' => $this->pending,
            'performed_on' => $this->performedOn,
            'notes' => $this->notes,
        ];
    }
}
