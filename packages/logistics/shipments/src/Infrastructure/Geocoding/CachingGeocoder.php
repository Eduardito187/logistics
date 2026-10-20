<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Geocoding;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Shipments\Domain\Contract\Geocoder;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Domain\ValueObject\GeocodeResult;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;

/**
 * Decorador con DEDUP PERSISTENTE del geocodificador (patrón decorador de Infrastructure, como los de
 * caché de network/promise). Envuelve a cualquier Geocoder real y, ANTES de pegarle, busca la consulta
 * en la tabla normalizada `geocoding_cache`:
 *
 *  - ACIERTO: reconstruye el GeocodeResult desde la fila (sin I/O externo), incrementa `hits` y refresca
 *    `last_hit_at` (métrica de repetición). El geocoder real NO se invoca.
 *  - FALLO: delega en el geocoder real y PERSISTE el resultado (dedup para las próximas resoluciones de
 *    la misma dirección).
 *
 * La clave agrupa variantes de la misma dirección: la consulta se normaliza (trim + minúsculas + colapso
 * de espacios) y el hash incluye proveedor y país para no mezclar cachés distintas. Así "Av. Arce 2345",
 * "av.  arce 2345 " y "AV. ARCE 2345" caen en la misma fila.
 *
 * Respeta DDD/Clean Arch: el dominio solo ve el contrato Geocoder; la persistencia es de Infrastructure.
 * Respeta la regla #7: en la fila vive la forma primitiva (escalares + payload array), nunca el objeto.
 */
final readonly class CachingGeocoder implements Geocoder
{
    public function __construct(
        private Geocoder $inner,
        private ConnectionInterface $connection,
        private string $provider,
        private string $country,
        private string $table = 'geocoding_cache',
    ) {}

    public function geocode(Address $address): GeocodeResult
    {
        $normalized = $this->normalizedQuery($address);
        $hash = $this->hashFor($normalized);

        $row = $this->connection->table($this->table)->where('query_hash', $hash)->first();
        if ($row !== null) {
            $result = $this->fromRow($row);
            if ($result !== null) {
                $this->registerHit($hash);

                return $result;
            }
        }

        // Fallo (o fila corrupta de un esquema anterior): se resuelve de verdad y se persiste.
        $result = $this->inner->geocode($address);
        $this->store($hash, $normalized, $result);

        return $result;
    }

    public function score(Address $address): AddressConfidence
    {
        return $this->geocode($address)->confidence;
    }

    /**
     * Normaliza la consulta para que variantes de la misma dirección compartan clave: une texto y
     * referencia, colapsa espacios, recorta y pasa a minúsculas (Unicode-aware).
     */
    private function normalizedQuery(Address $address): string
    {
        $raw = $address->reference === null
            ? $address->text
            : $address->text.', '.$address->reference;

        $collapsed = preg_replace('/\s+/u', ' ', $raw) ?? $raw;

        return mb_strtolower(trim($collapsed));
    }

    /**
     * Hash de dedup: incluye proveedor y país para no mezclar resultados de cachés distintas.
     */
    private function hashFor(string $normalized): string
    {
        // SHA-256 (no SHA-1/MD5): es solo una clave de deduplicación, no un contexto de seguridad, pero se
        // usa un algoritmo fuerte para no disparar alertas de hash débil. 64 hex → columna query_hash varchar(64).
        return hash('sha256', $this->provider.'|'.$this->country.'|'.$normalized);
    }

    /**
     * Incrementa el contador de aciertos y refresca la marca del último acierto (métrica de repetición).
     */
    private function registerHit(string $hash): void
    {
        $this->connection->table($this->table)
            ->where('query_hash', $hash)
            ->update([
                'hits' => $this->connection->raw('hits + 1'),
                'last_hit_at' => CarbonImmutable::now()->toDateTimeString(),
            ]);
    }

    /**
     * Persiste el resultado recién resuelto. insertOrIgnore evita que dos workers que fallan a la vez
     * (SELECT ... SKIP LOCKED del outbox corre en paralelo) choquen contra el único de query_hash.
     */
    private function store(string $hash, string $normalized, GeocodeResult $result): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->table)->insertOrIgnore([
            'query_hash' => $hash,
            'normalized_query' => $normalized,
            'provider' => $this->provider,
            'lat' => $result->geoPoint?->lat,
            'lng' => $result->geoPoint?->lng,
            'match_type' => $result->matchType->value,
            'confidence_score' => $result->confidence->score,
            'payload' => json_encode($this->toPayload($result)),
            'hits' => 1,
            'created_at' => $now,
            'last_hit_at' => $now,
        ]);
    }

    /**
     * Forma primitiva del resultado para la columna payload (regla #7).
     *
     * @return array{score: int, match: string, lat: float|null, lng: float|null}
     */
    private function toPayload(GeocodeResult $result): array
    {
        return [
            'score' => $result->confidence->score,
            'match' => $result->matchType->value,
            'lat' => $result->geoPoint?->lat,
            'lng' => $result->geoPoint?->lng,
        ];
    }

    /**
     * Reconstruye el GeocodeResult desde la fila cacheada; null si los datos no tienen la forma esperada
     * (fila de un esquema anterior, por ejemplo), para forzar una resolución limpia.
     */
    private function fromRow(object $row): ?GeocodeResult
    {
        $score = $row->confidence_score ?? null;
        $match = $row->match_type ?? null;
        if (!is_numeric($score) || !is_string($match)) {
            return null;
        }

        $matchType = GeocodeMatchType::tryFrom($match);
        if ($matchType === null) {
            return null;
        }

        $lat = $row->lat ?? null;
        $lng = $row->lng ?? null;
        $geoPoint = is_numeric($lat) && is_numeric($lng)
            ? GeoPoint::of((float) $lat, (float) $lng)
            : null;

        return GeocodeResult::of(AddressConfidence::of((int) $score), $matchType, $geoPoint);
    }
}
