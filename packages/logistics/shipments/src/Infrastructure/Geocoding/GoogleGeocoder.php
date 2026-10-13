<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Geocoding;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpClient;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Domain\ValueObject\GeocodeResult;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;
use Throwable;

/**
 * Adaptador real sobre la Google Geocoding API.
 *
 * Traduce la respuesta de Google a un GeocodeResult que el dominio entiende: la precisión geométrica
 * (location_type) define el tipo de coincidencia (exacta/interpolada/aproximada) y, con la marca
 * partial_match, el score de confianza vía GeocodeScoring; la geometría da la coordenada. El dominio
 * nunca ve a Google; solo recibe el resultado normalizado.
 *
 * Decisiones de infraestructura que viven acá, no en el dominio:
 *  - Caché (Valkey/Redis): las direcciones casi no cambian; evita cuota y acelera reprocesos. Se cachea
 *    la forma primitiva (array), nunca el objeto de dominio (regla 7 del proyecto).
 *  - Cuota diaria: corta el gasto antes de llamar a la API cuando se supera el presupuesto.
 *  - Manejo de errores: transporte, respuesta inválida y estados de cuota se elevan como
 *    GeocodingException para que el worker del outbox reintente.
 */
final class GoogleGeocoder extends AbstractGeocoder
{
    /** Prefijo de las claves de caché de resultados de geocodificación. */
    private const CACHE_PREFIX = 'shipments:geocoder:google:result:';

    /** Prefijo del contador diario de requests reales (para la cuota). */
    private const QUOTA_PREFIX = 'shipments:geocoder:google:quota:';

    /**
     * Mapa precisión geométrica de Google → tipo de coincidencia de dominio.
     *
     * @var array<string, GeocodeMatchType>
     */
    private const LOCATION_TYPE_MATCH = [
        'ROOFTOP' => GeocodeMatchType::Exact,             // coordenada sobre el techo exacto
        'RANGE_INTERPOLATED' => GeocodeMatchType::Interpolated, // interpolada entre dos puntos de la calle
        'GEOMETRIC_CENTER' => GeocodeMatchType::Approximate,    // centro de una manzana/polígono
        'APPROXIMATE' => GeocodeMatchType::Approximate,         // aproximada (localidad, zona)
    ];

    public function __construct(
        private readonly HttpClient $http,
        private readonly Cache $cache,
        private readonly GeocodeScoring $scoring,
        private readonly string $apiKey,
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $language,
        private readonly int $timeout,
        private readonly int $cacheTtl,
        private readonly int $dailyQuota,
    ) {}

    public function geocode(Address $address): GeocodeResult
    {
        $cacheKey = self::CACHE_PREFIX.sha1($this->queryText($address));

        /** @var mixed $cached */
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            $result = $this->fromPrimitive($cached);
            if ($result !== null) {
                return $result;
            }
        }

        $this->guardQuota();

        $result = $this->resolve($address);

        // Regla 7 del proyecto: se cachea la forma primitiva (array), nunca el objeto de dominio.
        $this->cache->put($cacheKey, $this->toPrimitive($result), $this->cacheTtl);

        return $result;
    }

    /**
     * Llama a Google y traduce la respuesta a un GeocodeResult.
     */
    private function resolve(Address $address): GeocodeResult
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->get($this->endpoint, [
                    'address' => $this->queryText($address),
                    'region' => $this->region,
                    'language' => $this->language,
                    'key' => $this->apiKey,
                ]);
        } catch (Throwable $e) {
            throw GeocodingException::because('error de transporte al geocodificar: '.$e->getMessage());
        }

        if (!$response->successful()) {
            throw GeocodingException::because('Google respondió HTTP '.$response->status());
        }

        /** @var mixed $body */
        $body = $response->json();
        if (!is_array($body)) {
            throw GeocodingException::because('respuesta de Google no es un objeto JSON');
        }

        $status = is_string($body['status'] ?? null) ? $body['status'] : 'UNKNOWN_ERROR';

        return match ($status) {
            'OK' => $this->resultFromResults($body['results'] ?? null),
            'ZERO_RESULTS' => GeocodeResult::notFound(AddressConfidence::of($this->scoring->noResultScore())),
            'OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT' => throw GeocodingException::quotaExceeded($this->dailyQuota),
            default => throw GeocodingException::because("Google devolvió estado {$status}"),
        };
    }

    /**
     * Deriva el resultado del primer candidato: tipo de coincidencia y coordenada, con score vía scoring.
     *
     * @param mixed $results
     */
    private function resultFromResults($results): GeocodeResult
    {
        if (!is_array($results) || !isset($results[0]) || !is_array($results[0])) {
            return GeocodeResult::notFound(AddressConfidence::of($this->scoring->noResultScore()));
        }

        $top = $results[0];

        $geometry = is_array($top['geometry'] ?? null) ? $top['geometry'] : [];
        $locationType = is_string($geometry['location_type'] ?? null) ? $geometry['location_type'] : 'APPROXIMATE';
        $matchType = self::LOCATION_TYPE_MATCH[$locationType] ?? GeocodeMatchType::Approximate;

        $partialMatch = ($top['partial_match'] ?? false) === true;
        $score = $this->scoring->scoreFor($matchType, $partialMatch);

        return GeocodeResult::of(
            AddressConfidence::of($score),
            $matchType,
            $this->geoPointFrom($geometry['location'] ?? null),
        );
    }

    /**
     * Extrae la coordenada de geometry.location; null si no vino o quedó fuera de rango.
     *
     * @param mixed $location
     */
    private function geoPointFrom($location): ?GeoPoint
    {
        if (!is_array($location)) {
            return null;
        }

        $lat = $location['lat'] ?? null;
        $lng = $location['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return GeoPoint::of($lat, $lng);
    }

    /**
     * Cuenta los requests reales del día y corta si se supera la cuota configurada (0 = sin límite).
     */
    private function guardQuota(): void
    {
        if ($this->dailyQuota <= 0) {
            return;
        }

        $key = self::QUOTA_PREFIX.gmdate('Y-m-d');

        $used = (int) $this->cache->get($key, 0);
        if ($used >= $this->dailyQuota) {
            throw GeocodingException::quotaExceeded($this->dailyQuota);
        }

        // El contador vive un día; se recrea solo al primer request de la jornada siguiente.
        $this->cache->put($key, $used + 1, 86400);
    }
}
