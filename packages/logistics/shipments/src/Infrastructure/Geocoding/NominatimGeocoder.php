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
 * Adaptador sobre Nominatim (OpenStreetMap), el geocoder open-source self-host del proyecto.
 *
 * Es gratis y no requiere API key, a cambio de respetar la política de uso de OSM (aplica igual
 * contra la instancia self-host, para no saturarla):
 *  - User-Agent identificable obligatorio (si no, OSM bloquea; va por configuración).
 *  - Máximo ~1 request por segundo: se auto-limita con un throttle best-effort apoyado en la caché
 *    (clave compartida del último request), para no gastar llamadas innecesarias en reprocesos masivos.
 *
 * Traduce los campos del resultado (addresstype/class/type) al tipo de coincidencia de dominio y puntúa
 * con los criterios configurables de GeocodeScoring. Cachea la forma primitiva (array),
 * nunca el objeto de dominio (regla 7 del proyecto).
 */
final class NominatimGeocoder extends AbstractGeocoder
{
    /** Prefijo de las claves de caché de resultados. */
    private const CACHE_PREFIX = 'shipments:geocoder:nominatim:result:';

    /** Clave del timestamp (ms) del último request real, para el throttle del rate-limit. */
    private const THROTTLE_KEY = 'shipments:geocoder:nominatim:last_request_ms';

    /**
     * Tipos de lugar (addresstype / class / type de OSM) que indican coincidencia EXACTA (sobre el predio).
     *
     * @var list<string>
     */
    private const EXACT_TYPES = ['building', 'house', 'address', 'amenity', 'shop', 'office'];

    /**
     * Tipos de vía que indican coincidencia INTERPOLADA (sobre la calle, sin número exacto).
     *
     * @var list<string>
     */
    private const INTERPOLATED_TYPES = ['road', 'street', 'highway', 'pedestrian', 'footway'];

    public function __construct(
        private readonly HttpClient $http,
        private readonly Cache $cache,
        private readonly GeocodeScoring $scoring,
        private readonly string $endpoint,
        private readonly string $userAgent,
        private readonly string $countryCodes,
        private readonly string $language,
        private readonly int $timeout,
        private readonly int $cacheTtl,
        private readonly int $minIntervalMs,
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

        $this->throttle();

        $result = $this->resolve($address);

        // Regla 7 del proyecto: se cachea la forma primitiva (array), nunca el objeto de dominio.
        $this->cache->put($cacheKey, $this->toPrimitive($result), $this->cacheTtl);

        return $result;
    }

    /**
     * Llama a Nominatim y traduce la respuesta a un GeocodeResult.
     */
    private function resolve(Address $address): GeocodeResult
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->withHeaders(['User-Agent' => $this->userAgent])
                ->get($this->endpoint, [
                    'q' => $this->queryText($address),
                    'format' => 'jsonv2',
                    'limit' => 1,
                    'addressdetails' => 0,
                    'countrycodes' => $this->countryCodes,
                    'accept-language' => $this->language,
                ]);
        } catch (Throwable $e) {
            throw GeocodingException::because('error de transporte al geocodificar: '.$e->getMessage());
        }

        if (!$response->successful()) {
            throw GeocodingException::because('Nominatim respondió HTTP '.$response->status());
        }

        /** @var mixed $body */
        $body = $response->json();
        if (!is_array($body)) {
            throw GeocodingException::because('respuesta de Nominatim no es una lista JSON');
        }

        if (!isset($body[0]) || !is_array($body[0])) {
            return GeocodeResult::notFound(AddressConfidence::of($this->scoring->noResultScore()));
        }

        return $this->resultFromCandidate($body[0]);
    }

    /**
     * Deriva el resultado del primer candidato: tipo de coincidencia y coordenada, con score vía scoring.
     *
     * @param array<array-key, mixed> $candidate
     */
    private function resultFromCandidate(array $candidate): GeocodeResult
    {
        $matchType = $this->matchTypeFrom($candidate);
        $score = $this->scoring->scoreFor($matchType);

        return GeocodeResult::of(
            AddressConfidence::of($score),
            $matchType,
            $this->geoPointFrom($candidate),
        );
    }

    /**
     * Mapea addresstype/category/type de OSM al tipo de coincidencia de dominio.
     *
     * @param array<array-key, mixed> $candidate
     */
    private function matchTypeFrom(array $candidate): GeocodeMatchType
    {
        $signals = [];
        foreach (['addresstype', 'category', 'class', 'type'] as $field) {
            $value = $candidate[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $signals[] = strtolower($value);
            }
        }

        foreach ($signals as $signal) {
            if (in_array($signal, self::EXACT_TYPES, true)) {
                return GeocodeMatchType::Exact;
            }
        }
        foreach ($signals as $signal) {
            if (in_array($signal, self::INTERPOLATED_TYPES, true)) {
                return GeocodeMatchType::Interpolated;
            }
        }

        return GeocodeMatchType::Approximate;
    }

    /**
     * Extrae la coordenada (lat/lon vienen como string en Nominatim); null si falta o queda fuera de rango.
     *
     * @param array<array-key, mixed> $candidate
     */
    private function geoPointFrom(array $candidate): ?GeoPoint
    {
        $lat = $candidate['lat'] ?? null;
        $lng = $candidate['lon'] ?? null;
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
     * Auto-limita el ritmo para respetar la política de OSM (máx ~1 req/s). Best-effort: usa la caché
     * compartida como reloj del último request y duerme lo que falte. No es un lock distribuido fuerte,
     * pero evita el abuso en reprocesos de un solo worker, que es el caso real.
     */
    private function throttle(): void
    {
        if ($this->minIntervalMs <= 0) {
            return;
        }

        $now = (int) round(microtime(true) * 1000);
        $last = (int) $this->cache->get(self::THROTTLE_KEY, 0);
        $wait = $last + $this->minIntervalMs - $now;

        if ($wait > 0 && $wait <= $this->minIntervalMs) {
            usleep($wait * 1000);
            $now += $wait;
        }

        $this->cache->put(self::THROTTLE_KEY, $now, 60);
    }
}
