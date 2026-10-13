<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

/**
 * Cupo que las reglas dicen que DEBERÍA existir para una fecha/franja/unidad/scope. Es el resultado de
 * proyectar las reglas sobre el horizonte; todavía no es un CapacityBucket persistido. Quien genera el
 * calendario lo materializa (define el bucket si aún no existe), respetando la clave natural.
 */
final readonly class PlannedBucket
{
    public function __construct(
        public BucketScope $scope,
        public SlotDate $date,
        public TimeSlot $slot,
        public CapacityUnit $unit,
        public Quantity $total,
    ) {}
}
