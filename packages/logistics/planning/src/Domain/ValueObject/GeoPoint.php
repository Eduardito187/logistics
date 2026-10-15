<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Punto geográfico (latitud/longitud) propio del contexto de Planificación. Se usa como entrada del
 * cálculo de la MATRIZ de distancias/tiempos y de la geometría de la ruta.
 *
 * Es deliberadamente local al contexto (no se importa el GeoPoint de Cobertura) para que el dominio de
 * Planificación siga siendo PHP puro e independiente de otros contextos: las coordenadas las aporta quien
 * conoce el envío o el nodo, y acá solo viajan como dato primitivo.
 */
final readonly class GeoPoint
{
    private function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    public static function of(float $latitude, float $longitude): self
    {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw PlanningException::because("latitud fuera de rango (-90..90): {$latitude}");
        }
        if ($longitude < -180.0 || $longitude > 180.0) {
            throw PlanningException::because("longitud fuera de rango (-180..180): {$longitude}");
        }

        return new self($latitude, $longitude);
    }

    /**
     * Clave estable del punto, redondeada a ~1 m (6 decimales), para construir la clave de caché de la
     * matriz sin que el ruido de los últimos decimales multiplique entradas equivalentes.
     */
    public function cacheKey(): string
    {
        return number_format($this->latitude, 6, '.', '').','.number_format($this->longitude, 6, '.', '');
    }

    /**
     * Distancia en METROS a otro punto por la fórmula de haversine (gran círculo). Sirve de degradación
     * cuando el ruteador no responde y para comparar cercanía sin red.
     */
    public function haversineMeters(self $other): float
    {
        $earthRadiusMeters = 6_371_000.0;

        $deltaLat = deg2rad($other->latitude - $this->latitude);
        $deltaLng = deg2rad($other->longitude - $this->longitude);
        $lat1 = deg2rad($this->latitude);
        $lat2 = deg2rad($other->latitude);

        $h = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return 2 * $earthRadiusMeters * asin(min(1.0, sqrt($h)));
    }
}
