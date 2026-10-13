<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Geocoding;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpClient;
use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Throwable;

/**
 * Adaptador real del scorer de direcciones sobre la Google Geocoding API.
 *
 * Traduce la respuesta de Google a un score 0..100 que el dominio entiende (ver AddressConfidenceScorer):
 * la precisión geométrica reportada por Google (location_type) y la marca partial_match definen cuánta
 * confianza merece la dirección. El dominio nunca ve a Google; solo recibe un AddressConfidence.
 *
 * Decisiones de infraestructura que viven acá, no en el dominio:
 *  - Caché (Valkey/Redis): las direcciones casi no cambian, así evitamos cuota y aceleramos reprocesos.
 *  - Cuota diaria: corta el gasto antes de llamar a la API cuando se supera el presupuesto.
 *  - Manejo de errores: transporte, respuesta inválida y estados de cuota se elevan como
 *    GeocodingException para que el worker del outbox reintente.
 */
final class GoogleGeocoder implements AddressConfidenceScorer
{
    /** Prefijo de las claves de caché de resultados de geocodificación. */
    private const CACHE_PREFIX = 'shipments:geocoder:result:';

    /** Prefijo del contador diario de requests reales (para la cuota). */
    private const QUOTA_PREFIX = 'shipments:geocoder:quota:';

    /**
     * Mapa precisión geométrica de Google → score base de confianza.
     *
     * @var array<string, int>
     */
    private const LOCATION_TYPE_SCORE = [
        'ROOFTOP' => 95,            // coordenada sobre el techo exacto
        'RANGE_INTERPOLATED' => 80, // interpolada entre dos puntos conocidos de la calle
        'GEOMETRIC_CENTER' => 60,   // centro de una manzana/polígono
        'APPROXIMATE' => 40,        // aproximada (localidad, zona)
    ];

    /** Penalización al score cuando Google marca partial_match (coincidencia parcial). */
    private const PARTIAL_MATCH_PENALTY = 25;

    /** Score para direcciones sin resultado (ZERO_RESULTS): confianza baja, candidata a confirmar. */
    private const NO_RESULT_SCORE = 10;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Cache $cache,
        private readonly string $apiKey,
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $language,
        private readonly int $timeout,
        private readonly int $cacheTtl,
        private readonly int $dailyQuota,
    ) {}

    public function score(Address $address): AddressConfidence
    {
        $cacheKey = self::CACHE_PREFIX.sha1($this->queryText($address));

        /** @var int|null $cached */
        $cached = $this->cache->get($cacheKey);
        if (is_int($cached)) {
            return AddressConfidence::of($cached);
        }

        $this->guardQuota();

        $score = $this->resolveScore($address);

        // Rule 7 del proyecto: se cachea la forma primitiva (int), nunca el objeto de dominio.
        $this->cache->put($cacheKey, $score, $this->cacheTtl);

        return AddressConfidence::of($score);
    }

    /**
     * Llama a Google y traduce la respuesta a un score 0..100.
     */
    private function resolveScore(Address $address): int
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
            'OK' => $this->scoreFromResults($body['results'] ?? null),
            'ZERO_RESULTS' => self::NO_RESULT_SCORE,
            'OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT' => throw GeocodingException::quotaExceeded($this->dailyQuota),
            default => throw GeocodingException::because("Google devolvió estado {$status}"),
        };
    }

    /**
     * Deriva el score del primer resultado: precisión geométrica menos penalización por coincidencia parcial.
     *
     * @param mixed $results
     */
    private function scoreFromResults($results): int
    {
        if (!is_array($results) || !isset($results[0]) || !is_array($results[0])) {
            return self::NO_RESULT_SCORE;
        }

        $top = $results[0];

        $geometry = $top['geometry'] ?? null;
        $locationType = is_array($geometry) && is_string($geometry['location_type'] ?? null)
            ? $geometry['location_type']
            : 'APPROXIMATE';

        $score = self::LOCATION_TYPE_SCORE[$locationType] ?? self::LOCATION_TYPE_SCORE['APPROXIMATE'];

        if (($top['partial_match'] ?? false) === true) {
            $score -= self::PARTIAL_MATCH_PENALTY;
        }

        return max(0, min(100, $score));
    }

    /**
     * Texto de consulta: la dirección y, si existe, la referencia del cliente, normalizados.
     */
    private function queryText(Address $address): string
    {
        $reference = $address->reference;

        return $reference === null
            ? $address->text
            : $address->text.', '.$reference;
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
