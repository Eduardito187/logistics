<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\ReadSide;

use Closure;
use Illuminate\Cache\Repository;
use Logistics\Tracking\Application\ReadModel\PublicLinkState;
use stdClass;

/**
 * Caché de lectura del enlace público, por token. Único dueño de las CLAVES y los TTL: lo usan el
 * lector cacheado ({@see CachedPublicTrackingReader}) para leer y el decorador del repositorio para
 * invalidar, así la forma de la clave no se repite en ningún otro lado.
 *
 * - Guarda la forma PRIMITIVA de la foto (array), nunca objetos serializados (regla 7).
 * - Anti-stampede con `Cache::flexible`: fresco `ttl_fresh` s; hasta `ttl_stale` s sirve el valor
 *   guardado y UN solo request lo refresca en segundo plano. A 2k clientes consultando el mismo enlace,
 *   al vencer la ventana fresca NO recalculan todos contra la base.
 * - Caché negativo: la foto `not_found` se guarda con un TTL duro y corto (`ttl_negative`), sin ventana
 *   stale: un barrido de tokens inexistentes pega a la base una vez por token y por ventana.
 * - La clave lleva el SHA-256 del token, no el token: el token es la credencial del enlace y no debe
 *   quedar legible en el keyspace de Valkey (MONITOR, dumps, logs lentos).
 *
 * Octane-safe: no memoiza nada en el proceso; el estado vive solo en el store.
 */
final class PublicTrackingCache
{
    /** Prefijo de la clave. Subir la versión si cambia la forma primitiva de la foto. */
    private const string KEY_PREFIX = 'tracking:public:v1:';

    /**
     * Clave hermana que `Cache::flexible` usa para la marca de creación. Se olvida junto con el valor:
     * así un refresco en segundo plano que ya estaba en vuelo detecta el cambio y NO reescribe la foto
     * vieja encima de la invalidación.
     */
    private const string FLEXIBLE_CREATED_PREFIX = 'illuminate:cache:flexible:created:';

    private readonly int $ttlFresh;

    private readonly int $ttlStale;

    private readonly int $ttlNegative;

    public function __construct(
        private readonly Repository $store,
        private readonly bool $enabled,
        int $ttlFresh,
        int $ttlStale,
        int $ttlNegative,
    ) {
        // Valores defensivos: una config en cero o invertida no debe apagar el anti-stampede ni dejar
        // entradas eternas.
        $this->ttlFresh = max(1, $ttlFresh);
        $this->ttlStale = max($this->ttlFresh + 1, $ttlStale);
        $this->ttlNegative = max(1, min($ttlNegative, $this->ttlFresh));
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Devuelve la foto primitiva del token; si no está (o venció) la resuelve con `$resolver`.
     *
     * @param  Closure(): array<string, mixed> $resolver
     * @return array<string, mixed>
     */
    public function remember(string $token, Closure $resolver): array
    {
        $key = $this->key($token);
        // Marca si ESTA llamada resolvió contra la base (objeto y no variable por referencia, para que el
        // análisis estático no la dé por constante).
        $run = new stdClass;
        $run->resolved = false;

        /** @var array<string, mixed> $snapshot */
        $snapshot = $this->store->flexible(
            $key,
            [$this->ttlFresh, $this->ttlStale],
            static function () use ($resolver, $run): array {
                $run->resolved = true;

                return $resolver();
            },
        );

        // Caché negativo: recién resuelto y sin seguimiento ⇒ se acorta su vida a un TTL duro. Al
        // vencer el valor, `flexible` lo trata como ausente y vuelve a resolver.
        if ($run->resolved === true && ($snapshot['state'] ?? null) === PublicLinkState::NotFound->value) {
            $this->store->put($key, $snapshot, $this->ttlNegative);
        }

        return $snapshot;
    }

    /**
     * Foto primitiva guardada, sin resolver ni refrescar. Null si no hay entrada.
     *
     * @return array<array-key, mixed>|null
     */
    public function peek(string $token): ?array
    {
        $snapshot = $this->store->get($this->key($token));

        return is_array($snapshot) ? $snapshot : null;
    }

    /** Olvida la foto del token (y la marca de creación de `flexible`). */
    public function forget(string $token): void
    {
        $key = $this->key($token);

        $this->store->forget($key);
        $this->store->forget(self::FLEXIBLE_CREATED_PREFIX.$key);
    }

    private function key(string $token): string
    {
        return self::KEY_PREFIX.hash('sha256', $token);
    }
}
