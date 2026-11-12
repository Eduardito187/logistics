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

        $key = self::PREFIX.$name.':'.hash('sha256', (string) json_encode($keyParts));

        $cached = $this->lookup($key);
        if (is_array($cached)) {
            return $cached;
        }

        $value = $compute();

        // Solo se guarda si el store respondió a la lectura; si falló, se calcula directo sin reintentar.
        if ($cached === null) {
            $this->store($key, $value);
        }

        return $value;
    }

    /**
     * Lee la clave: array = acierto; null = no está (el store respondió); false = el store falló.
     *
     * @return array<array-key, mixed>|false|null
     */
    private function lookup(string $key): array | false | null
    {
        try {
            $cached = $this->cache->get($key);
        } catch (Throwable) {
            return false;
        }

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function store(string $key, array $value): void
    {
        try {
            $this->cache->put($key, $value, $this->ttlSeconds);
        } catch (Throwable) {
            // La caché es best-effort: si el store no responde se devuelve el valor recién calculado.
        }
    }
}
