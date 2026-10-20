<?php

declare(strict_types=1);

namespace Logistics\Settings\Infrastructure\Integration;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Crypt;
use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Domain\ValueObject\SettingKey;
use Logistics\Settings\Infrastructure\Cache\SettingsCacheKey;
use Logistics\Shared\Domain\Clock\Clock;
use Throwable;

/**
 * Resolver de configuración de integraciones en RUNTIME.
 *
 * Lee la versión vigente de una clave de `settings_versions` (lo que se guarda desde el panel de
 * Integraciones) y, si no hay nada configurado desde el admin, deja que el llamador caiga a config()/env
 * pasando su valor por defecto. Degradación con gracia: ante CUALQUIER problema (DB caída, tabla aún no
 * migrada, clave inválida, secreto no descifrable) NUNCA lanza; devuelve el fallback o `[]`. Así la
 * plataforma arranca igual sin configuración persistida.
 *
 * Es plomería de Infraestructura (usa Eloquent vía el repositorio y el facade Crypt), fuera del dominio.
 */
final class IntegrationConfig
{
    /** Red de seguridad del cache (la publicación de una versión nueva lo invalida antes). */
    private const int CACHE_TTL_SECONDS = 600;

    /**
     * Memo por instancia (no es singleton: vive lo que dura el que lo pidió, p. ej. el boot de un
     * request). Evita repetir la lectura en cada enabled()/value()/secret() de la misma clave.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly Clock $clock,
        private readonly ?CacheRepository $cache = null,
    ) {}

    /**
     * Arreglo crudo guardado para la clave (los secretos siguen CIFRADOS). `[]` si no hay nada o si falla.
     *
     * Se lee en el boot de cada request (IntegrationRuntimeOverrides): por eso va a cache, incluido el
     * caso "no configurado", e invalidado por CacheInvalidatingSettingsRepository al publicar.
     *
     * @return array<string, mixed>
     */
    public function config(string $key): array
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        try {
            $cacheKey = SettingsCacheKey::integrationValue($key);
            $cached = $this->cache?->get($cacheKey);
            if (is_array($cached) && isset($cached['value']) && is_array($cached['value'])) {
                /** @var array<string, mixed> $value */
                $value = $cached['value'];
            } else {
                $version = $this->repository->currentFor(SettingKey::fromString($key), $this->clock->now());
                $value = $version === null ? [] : $version->value;
                $this->cache?->put($cacheKey, ['value' => $value], self::CACHE_TTL_SECONDS);
            }

            return $this->memo[$key] = $value;
        } catch (Throwable) {
            // Degrada: sin DB / tabla / cache / clave válida se comporta como "no configurado" (sin
            // memoizar ni cachear, para reintentar en la próxima lectura).
            return [];
        }
    }

    /**
     * ¿La integración está habilitada? Si el admin nunca la configuró, devuelve `$fallback`.
     */
    public function enabled(string $key, bool $fallback = false): bool
    {
        $config = $this->config($key);
        if (!array_key_exists('enabled', $config)) {
            return $fallback;
        }

        return (bool) $config['enabled'];
    }

    /**
     * Valor NO secreto de un parámetro. Cae a `$fallback` si no está o viene vacío (null/'').
     */
    public function value(string $key, string $param, mixed $fallback = null): mixed
    {
        $config = $this->config($key);
        if (!array_key_exists($param, $config)) {
            return $fallback;
        }

        /** @var mixed $value */
        $value = $config[$param];
        if ($value === null || $value === '') {
            return $fallback;
        }

        return $value;
    }

    /**
     * Valor SECRETO de un parámetro: descifra lo guardado con Crypt::encryptString. Si no se puede
     * descifrar (p. ej. el valor quedó en claro de un entorno viejo) devuelve el crudo; si no hay nada,
     * `$fallback`. Nunca lanza.
     */
    public function secret(string $key, string $param, ?string $fallback = null): ?string
    {
        $config = $this->config($key);
        /** @var mixed $raw */
        $raw = $config[$param] ?? null;
        if (!is_string($raw) || $raw === '') {
            return $fallback;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (Throwable) {
            return $raw;
        }
    }

    /**
     * ¿Hay un secreto guardado para ese parámetro? Para mostrar el placeholder "already set" en el panel
     * sin revelar el valor en claro.
     */
    public function hasSecret(string $key, string $param): bool
    {
        $config = $this->config($key);
        /** @var mixed $raw */
        $raw = $config[$param] ?? null;

        return is_string($raw) && $raw !== '';
    }
}
