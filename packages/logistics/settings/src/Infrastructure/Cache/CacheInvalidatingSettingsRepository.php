<?php

declare(strict_types=1);

namespace Logistics\Settings\Infrastructure\Cache;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Domain\SettingVersion;
use Logistics\Settings\Domain\ValueObject\SettingKey;

/**
 * Decorador del repositorio de configuración que invalida por evento el cache del valor vigente (frente
 * escala).
 *
 * NO cachea lecturas: nextVersionFor, currentFor e historyFor pasan intactos al repositorio real
 * (el camino de escritura calcula la versión siguiente leyendo de la base, sin pasar por cache). Lo
 * único que hace es, al publicar una versión nueva (add), olvidar la clave del valor vigente de esa
 * clave de configuración. Como toda publicación termina en add(), olvidar ahí cubre todos los caminos
 * de escritura.
 */
final readonly class CacheInvalidatingSettingsRepository implements SettingsRepository
{
    public function __construct(
        private SettingsRepository $inner,
        private CacheRepository $cache,
    ) {}

    public function nextVersionFor(SettingKey $key): int
    {
        return $this->inner->nextVersionFor($key);
    }

    public function add(SettingVersion $v): void
    {
        $this->inner->add($v);
        $this->cache->forget(SettingsCacheKey::currentValue($v->key->value));
        $this->cache->forget(SettingsCacheKey::integrationValue($v->key->value));
    }

    public function currentFor(SettingKey $key, DateTimeImmutable $at): ?SettingVersion
    {
        return $this->inner->currentFor($key, $at);
    }

    /**
     * @return list<SettingVersion>
     */
    public function historyFor(SettingKey $key): array
    {
        return $this->inner->historyFor($key);
    }
}
