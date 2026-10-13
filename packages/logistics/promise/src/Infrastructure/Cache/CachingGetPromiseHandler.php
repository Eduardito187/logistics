<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Promise\Application\Query\GetPromise\GetPromise;
use Logistics\Promise\Application\ReadModel\PromiseView;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Decorador con cache (Valkey) del handler que lee una promesa por id (frente E — escala).
 *
 * Es un adaptador de Infrastructure: envuelve al GetPromiseHandler de Application (PHP puro, sin
 * framework) y le agrega el cache, que es un detalle de infraestructura. Se registra en lugar del
 * handler plano para la consulta GetPromise.
 *
 * Respeta la regla #7: guarda la forma primitiva del modelo de lectura (PromiseView::toArray()) y lo
 * rehidrata con PromiseView::fromArray() al leer; nunca serializa el objeto. Solo cachea el caso
 * "encontrada": si la promesa no existe no se guarda nada (el null no se memoiza).
 *
 * Invalidación por evento: la entrada se olvida en CacheInvalidatingPromiseRepository::save(), es decir
 * ante cualquier cambio de la promesa (reservar, confirmar, vencer). El TTL es la red de seguridad.
 *
 * @implements QueryHandler<GetPromise, ?PromiseView>
 */
final readonly class CachingGetPromiseHandler implements QueryHandler
{
    /** Vida de la entrada; la promesa cambia poco una vez confirmada, pero el evento la invalida antes. */
    private const int TTL_SECONDS = 300;

    /**
     * @param QueryHandler<GetPromise, ?PromiseView> $inner
     */
    public function __construct(
        private QueryHandler $inner,
        private CacheRepository $cache,
    ) {}

    public function handle(Query $query): ?PromiseView
    {
        if (!$query instanceof GetPromise) {
            throw PromiseException::because('CachingGetPromiseHandler solo maneja GetPromise');
        }

        $key = PromiseViewCacheKey::forId($query->promiseId);

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return PromiseView::fromArray($cached);
        }

        $view = $this->inner->handle($query);
        if ($view instanceof PromiseView) {
            $this->cache->put($key, $view->toArray(), self::TTL_SECONDS);
        }

        return $view;
    }
}
