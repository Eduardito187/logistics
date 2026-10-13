<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Contract;

use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Contrato de las reglas de cupo configurables. Las reglas (p. ej. el total por defecto de un
 * nodo/zona para una franja y unidad) NO viven como código ni como estado del agregado: son
 * configuración versionada en `logistics/settings` (ver docs/architecture.md, principio 5).
 *
 * Este puerto deja el punto de extensión listo. La implementación que leerá de settings se cableará
 * más adelante; por ahora el contexto no la necesita para definir/consumir cupos explícitos.
 */
interface CapacityRuleProvider
{
    /**
     * Total de capacidad por defecto para un scope, fecha, franja y unidad, según las reglas vigentes.
     * Devuelve null si no hay regla aplicable (entonces el cupo debe definirse explícitamente).
     */
    public function defaultTotalFor(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
    ): ?Quantity;
}
