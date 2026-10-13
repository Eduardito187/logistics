<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Capacity\Domain\CapacityBucket;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityBucketId;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Decorador del repositorio de cupos que invalida por evento los caches derivados de la capacidad del
 * día (frente E — escala).
 *
 * No cachea lecturas: todas las consultas y los locks pesimistas pasan intactos al repositorio real,
 * para no introducir lecturas obsoletas en los caminos de escritura (consumo con lock). Lo único que
 * hace es, tras guardar un bucket, incrementar el contador de generación de su fecha
 * (CapacityGenerationKey). Como toda mutación de cupo —definir, consumir, liberar— termina en save(),
 * cualquier cambio sube la generación y deja frías las claves de cache que dependen de ese día.
 *
 * El incremento corre dentro de la transacción del CommandBus junto al save; si la transacción revierte,
 * el contador queda más alto de lo "necesario", lo que a lo sumo provoca un recálculo extra del cache,
 * nunca una lectura obsoleta. Es el lado seguro del compromiso.
 */
final readonly class CacheInvalidatingCapacityRepository implements CapacityRepository
{
    public function __construct(
        private CapacityRepository $inner,
        private CacheRepository $cache,
    ) {}

    public function nextIdentity(): CapacityBucketId
    {
        return $this->inner->nextIdentity();
    }

    public function save(CapacityBucket $bucket): void
    {
        $this->inner->save($bucket);
        $this->cache->increment(CapacityGenerationKey::forDate($bucket->date()->toString()));
    }

    public function findById(CapacityBucketId $id): ?CapacityBucket
    {
        return $this->inner->findById($id);
    }

    public function findBucket(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
    ): ?CapacityBucket {
        return $this->inner->findBucket($scope, $date, $slot, $unit);
    }

    public function findBucketForUpdate(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
    ): ?CapacityBucket {
        return $this->inner->findBucketForUpdate($scope, $date, $slot, $unit);
    }

    /**
     * @return list<CapacityBucket>
     */
    public function byDate(SlotDate $date): array
    {
        return $this->inner->byDate($date);
    }
}
