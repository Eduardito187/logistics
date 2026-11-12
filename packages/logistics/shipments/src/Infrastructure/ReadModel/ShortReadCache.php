<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Caché CORTA (segundos) de lecturas agregadas de tablero: resumen de envíos, conteos por estado y KPIs.
 *
 * Guarda SOLO la forma primitiva (array) del resultado, nunca el objeto (regla del proyecto). Con TTL 0
 * (o si el store falla) calcula directo: la caché es una optimización, nunca una dependencia.
 */
final readonly class ShortReadCache
{
    private const PREFIX = 'shipments:read:';

    public function __construct(
        private Cache $cache,
        private int $ttlSeconds,
    ) {}

    /**
     * @param  array<string, mixed>                $keyParts
     * @param  callable(): array<array-key, mixed> $compute
     * @return array<array-key, mixed>
     */
    public function remember(string $name, array $keyParts, callable $compute): array
    {
        if ($this->ttlSeconds <= 0) {
            return $compute();
        }

        $key = self::PREFIX.$name.':'.sha1((string) json_encode($keyParts));

        try {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            return $compute();
        }

        $value = $compute();

        try {
            $this->cache->put($key, $value, $this->ttlSeconds);
        } catch (Throwable) {
            // La caché es best-effort: si el store no responde se devuelve el valor recién calculado.
        }

        return $value;
    }
}
