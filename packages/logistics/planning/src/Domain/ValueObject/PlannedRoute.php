<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

/**
 * Ruta resuelta de UN camión por el backend de optimización: el vehículo, el departamento al que quedó
 * ligado, las paradas YA en el orden de reparto final, la ETA de cada parada y la geometría de la ruta por
 * calles (si se pudo calcular en el servidor).
 *
 * Es la unidad de salida del RoutingBackend; el runner la materializa en un DeliveryPlan.
 */
final readonly class PlannedRoute
{
    /**
     * @param list<DeliveryCandidate> $candidates paradas en el orden de reparto final
     * @param array<string, Eta>      $etas       ETA por id de envío
     */
    public function __construct(
        public string $vehicleId,
        public ?string $departmentId,
        public array $candidates,
        public array $etas,
        public ?RouteGeometry $geometry = null,
    ) {}

    public function stopCount(): int
    {
        return count($this->candidates);
    }

    /**
     * Ids de los envíos en el orden de reparto final.
     *
     * @return list<string>
     */
    public function orderedShipmentIds(): array
    {
        return array_map(
            static fn (DeliveryCandidate $candidate): string => $candidate->shipmentId,
            $this->candidates,
        );
    }
}
