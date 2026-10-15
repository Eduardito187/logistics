<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Cache;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\PromiseId;

/**
 * Decorador del repositorio de promesas que invalida por evento el cache del modelo de lectura
 * (frente E — escala).
 *
 * NO cachea lecturas: findById y reservedExpiredAt pasan intactos al repositorio real. Es a propósito:
 * los comandos (Reservar, Confirmar, Vencer) cargan la promesa con findById, la mutan y la guardan, y la
 * promesa no tiene lock pesimista; servir esa lectura desde cache arriesgaría perder actualizaciones
 * bajo concurrencia. Por eso solo cachea el modelo de lectura (CachingGetPromiseHandler), que es de solo
 * lectura, y aquí únicamente se OLVIDA la entrada al guardar.
 *
 * Como toda mutación de la promesa termina en save(), olvidar la clave en save() cubre todos los caminos
 * de escritura sin que el camino de escritura lea jamás del cache.
 */
final readonly class CacheInvalidatingPromiseRepository implements PromiseRepository
{
    public function __construct(
        private PromiseRepository $inner,
        private CacheRepository $cache,
    ) {}

    public function nextIdentity(): PromiseId
    {
        return $this->inner->nextIdentity();
    }

    public function save(DeliveryPromise $promise): void
    {
        $this->inner->save($promise);
        $this->cache->forget(PromiseViewCacheKey::forId($promise->id()->toString()));
    }

    public function findById(PromiseId $id): ?DeliveryPromise
    {
        return $this->inner->findById($id);
    }

    /**
     * @return list<PromiseId>
     */
    public function reservedExpiredAt(DateTimeImmutable $now): array
    {
        return $this->inner->reservedExpiredAt($now);
    }
}
