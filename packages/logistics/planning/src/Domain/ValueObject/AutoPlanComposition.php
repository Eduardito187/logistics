<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

/**
 * Salida completa del motor de empaque para un día: los camiones armados (cada uno con su carga) y los
 * pedidos que no se pudieron asignar (sin capacidad, sin camión del departamento, o sin ubicación).
 *
 * Es el resultado puro que el runner materializa en planes y en el resumen de la corrida.
 */
final readonly class AutoPlanComposition
{
    /**
     * @param list<TruckLoad>         $trucks
     * @param list<DeliveryCandidate> $unassigned
     */
    public function __construct(
        public array $trucks,
        public array $unassigned,
    ) {}

    public function trucksPlanned(): int
    {
        return count($this->trucks);
    }

    public function stopsPlanned(): int
    {
        $stops = 0;
        foreach ($this->trucks as $truck) {
            $stops += $truck->stopCount();
        }

        return $stops;
    }

    public function unassignedCount(): int
    {
        return count($this->unassigned);
    }
}
