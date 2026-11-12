<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Aptitud del vehículo para despachar, independiente de su estado operativo (VehicleStatus).
 *
 * Un checklist pre-salida con un ítem CRÍTICO fallido deja al vehículo NO APTO desde ese día: no se le
 * puede armar tripulación ni registrar salida desde esa fecha en adelante, hasta que un supervisor lo
 * habilite tras la reparación. Una falla crítica (frenos, motor) no se repara sola de un día para otro,
 * por eso el bloqueo no vence a medianoche: se levanta solo de forma explícita.
 */
final readonly class VehicleFitness
{
    /**
     * @param list<string> $failedItems claves de los ítems críticos que fallaron
     */
    private function __construct(
        public ?DateTimeImmutable $unfitSince,
        public ?string $reason,
        public ?string $inspectionId,
        public array $failedItems,
    ) {}

    public static function fit(): self
    {
        return new self(null, null, null, []);
    }

    /**
     * @param list<string> $failedItems
     */
    public static function unfit(
        DateTimeImmutable $since,
        string $reason,
        ?string $inspectionId,
        array $failedItems,
    ): self {
        return new self($since->setTime(0, 0), $reason, $inspectionId, array_values($failedItems));
    }

    public function isUnfit(): bool
    {
        return $this->unfitSince !== null;
    }

    /**
     * ¿Está apto para despachar en esa fecha? No apto desde `unfitSince` (inclusive) en adelante.
     */
    public function isFitOn(DateTimeImmutable $date): bool
    {
        if ($this->unfitSince === null) {
            return true;
        }

        return $date->format('Y-m-d') < $this->unfitSince->format('Y-m-d');
    }
}
