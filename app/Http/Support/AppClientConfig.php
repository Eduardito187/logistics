<?php

declare(strict_types=1);

namespace App\Http\Support;

/**
 * Configuración que el servidor dicta a la app interna (Flutter): versión mínima por plataforma, URL de la
 * tienda, feature flags, teselas del mapa, contacto de soporte e intervalos de sondeo.
 *
 * Lee `config('app_client.*')` y, si una clave no existe (p. ej. en un docker nuevo sin el archivo de
 * config ni variables), cae a defaults que FUNCIONAN: sin versión mínima (nada se bloquea), teselas OSM
 * públicas (las mismas del admin, `maps.tile_url`), sin soporte configurado e intervalos conservadores.
 *
 * Es el único lector de estas claves (middleware de versión y `GET /app/app-config`), así que el día que
 * la sección «Mobile app» de Configuración overlaye estos valores en config() en el boot (mismo patrón que
 * IntegrationRuntimeOverrides), ambos lo ven sin cambios aquí.
 */
final class AppClientConfig
{
    /**
     * Plataformas con versión mínima propia; cualquier otra usa `default`.
     *
     * @var list<string>
     */
    public const array PLATFORMS = ['android', 'ios', 'web'];

    /**
     * Intervalos de sondeo por defecto, en segundos.
     *
     * @var array<string, int>
     */
    private const array DEFAULT_POLLING = [
        'notifications_seconds' => 60,
        'tasks_seconds' => 60,
        'location_seconds' => 30,
        'outbox_drain_seconds' => 900,
        'app_config_seconds' => 3600,
    ];

    /**
     * Normaliza la plataforma reportada por el cliente (minúsculas, solo las conocidas); null si no aplica.
     */
    public static function normalizePlatform(mixed $platform): ?string
    {
        if (!is_string($platform)) {
            return null;
        }

        $normalized = strtolower(trim($platform));

        return in_array($normalized, self::PLATFORMS, true) ? $normalized : null;
    }

    /**
     * Versión mínima soportada para la plataforma (o la `default`); null = sin mínima.
     */
    public function minVersion(?string $platform): ?string
    {
        return $this->perPlatformVersion('app_client.version.min', $platform);
    }

    /**
     * Última versión publicada para la plataforma (o la `default`); null = no informada.
     */
    public function latestVersion(?string $platform): ?string
    {
        return $this->perPlatformVersion('app_client.version.latest', $platform);
    }

    /**
     * URL de la tienda/MDM donde actualizar la app para la plataforma (o la `default`); null = sin URL.
     */
    public function storeUrl(?string $platform): ?string
    {
        if ($platform !== null) {
            $url = $this->string("app_client.version.store_url.{$platform}");
            if ($url !== null) {
                return $url;
            }
        }

        return $this->string('app_client.version.store_url.default');
    }

    /**
     * ¿Se rechaza con 426 a un cliente por debajo de la mínima? (si es false solo se informa la cabecera).
     */
    public function enforceMinVersion(): bool
    {
        $value = config('app_client.version.enforce', true);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * Versiones mínimas por plataforma (las no configuradas salen null).
     *
     * @return array<string, string|null>
     */
    public function minVersions(): array
    {
        $versions = ['default' => $this->minVersion(null)];
        foreach (self::PLATFORMS as $platform) {
            $versions[$platform] = $this->minVersion($platform);
        }

        return $versions;
    }

    /**
     * Feature flags booleanos (nombre → encendido). Se descartan claves no string y valores no booleanos.
     *
     * @return array<string, bool>
     */
    public function featureFlags(): array
    {
        $raw = config('app_client.feature_flags', []);
        if (!is_array($raw)) {
            return [];
        }

        $flags = [];
        foreach ($raw as $name => $value) {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (is_string($name) && $name !== '' && $bool !== null) {
                $flags[$name] = $bool;
            }
        }

        ksort($flags);

        return $flags;
    }

    /**
     * Teselas del mapa: por defecto las mismas del admin (`maps.*`, OSM público).
     *
     * @return array{tile_url: string, attribution: string, max_zoom: int}
     */
    public function map(): array
    {
        $tileUrl = $this->string('app_client.map.tile_url')
            ?? $this->string('maps.tile_url')
            ?? 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

        $attribution = $this->string('app_client.map.attribution')
            ?? $this->string('maps.tile_attribution')
            ?? '© OpenStreetMap';

        $maxZoom = config('app_client.map.max_zoom', config('maps.tile_max_zoom', 19));

        return [
            'tile_url' => $tileUrl,
            'attribution' => html_entity_decode($attribution, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'max_zoom' => is_numeric($maxZoom) ? max(1, min(22, (int) $maxZoom)) : 19,
        ];
    }

    /**
     * Contacto de soporte que la app muestra en "Ayuda" (null = no configurado).
     *
     * @return array{email: string|null, phone: string|null, whatsapp: string|null, hours: string|null}
     */
    public function support(): array
    {
        return [
            'email' => $this->string('app_client.support.email'),
            'phone' => $this->string('app_client.support.phone'),
            'whatsapp' => $this->string('app_client.support.whatsapp'),
            'hours' => $this->string('app_client.support.hours'),
        ];
    }

    /**
     * Intervalos de sondeo en segundos (acotados a [5, 86400] para que un valor roto no tumbe la app).
     *
     * @return array<string, int>
     */
    public function polling(): array
    {
        $polling = [];
        foreach (self::DEFAULT_POLLING as $name => $default) {
            $value = config("app_client.polling.{$name}", $default);
            $polling[$name] = is_numeric($value) ? max(5, min(86400, (int) $value)) : $default;
        }

        return $polling;
    }

    /**
     * Ajustes de operación de terreno (radio de geocerca de llegada, en metros).
     *
     * @return array{geofence_radius_m: int}
     */
    public function field(): array
    {
        $radius = config('app_client.field.geofence_radius_m', 150);

        return [
            'geofence_radius_m' => is_numeric($radius) ? max(10, min(5000, (int) $radius)) : 150,
        ];
    }

    private function perPlatformVersion(string $prefix, ?string $platform): ?string
    {
        if ($platform !== null) {
            $version = AppVersion::normalize($this->string("{$prefix}.{$platform}"));
            if ($version !== null) {
                return $version;
            }
        }

        return AppVersion::normalize($this->string("{$prefix}.default"));
    }

    private function string(string $key): ?string
    {
        $value = config($key);

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
