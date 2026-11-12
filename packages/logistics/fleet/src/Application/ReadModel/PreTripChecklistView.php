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
     * @param list<string>        $failedCritical
     * @param list<string>        $missingCritical
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
        public bool $fitToDispatch = true,
        public array $failedCritical = [],
        public array $missingCritical = [],
        public ?string $checklistTemplateId = null,
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
            $inspection->fitToDispatch(),
            $inspection->evaluation()->failedCritical,
            $inspection->evaluation()->missingCritical,
            $inspection->evaluation()->templateId,
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string, items: array<string, bool>, passed: bool, pending: list<string>, performed_on: string, notes: string|null, fit_to_dispatch: bool, failed_critical: list<string>, missing_critical: list<string>, checklist_template_id: string|null}
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
            // Aditivo (#27): resultado contra la plantilla del servidor.
            'fit_to_dispatch' => $this->fitToDispatch,
            'failed_critical' => $this->failedCritical,
            'missing_critical' => $this->missingCritical,
            'checklist_template_id' => $this->checklistTemplateId,
        ];
    }
}
