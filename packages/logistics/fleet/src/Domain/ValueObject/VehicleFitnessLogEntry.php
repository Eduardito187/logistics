<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Asiento de la bitácora de aptitud de un vehículo: qué pasó (marcado NO APTO o habilitado), por qué, qué
 * ítems fallaron, desde qué inspección y quién lo hizo. El instante lo pone quien escribe en la bitácora.
 */
final readonly class VehicleFitnessLogEntry
{
    /**
     * @param list<string> $failedItems
     */
    public function __construct(
        public string $vehicleId,
        public string $action,
        public ?string $reason,
        public array $failedItems,
        public ?string $inspectionId,
        public ?string $actorId,
        public ?string $actorLabel,
    ) {}
}
