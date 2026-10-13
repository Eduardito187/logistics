<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Contract;

use Logistics\Capacity\Domain\CapacityBucket;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityBucketId;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Puerto de persistencia de buckets de cupo. El dominio lo define; la infraestructura (Eloquent) lo
 * implementa.
 *
 * nextIdentity() entrega el id antes de persistir, para que el agregado nazca ya con su identidad y
 * sus eventos puedan referenciarla.
 */
interface CapacityRepository
{
    public function nextIdentity(): CapacityBucketId;

    public function save(CapacityBucket $bucket): void;

    public function findById(CapacityBucketId $id): ?CapacityBucket;

    /**
     * Busca el bucket único que corresponde a una combinación scope/fecha/franja/unidad. Es la
     * clave natural del cupo: coincide con el índice único de la tabla. Devuelve null si no existe.
     */
    public function findBucket(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
    ): ?CapacityBucket;

    /**
     * Todos los buckets de una fecha, ordenados de forma estable (por franja y unidad).
     *
     * @return list<CapacityBucket>
     */
    public function byDate(SlotDate $date): array;
}
