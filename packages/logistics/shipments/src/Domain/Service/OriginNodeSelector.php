<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Service;

/**
 * Elige el nodo de ORIGEN de un envío cuando la fuente no lo informó: el centro de distribución (CD) desde
 * el que se prepara. Dominio PURO: recibe los CD de la red y los datos del envío en primitivos.
 *
 * Es la MISMA regla con la que las etiquetas calculan su origen (`org` del QR), replicada acá para que el
 * alcance por ciudad y la etiqueta digan lo mismo sin que este contexto dependa del de etiquetas:
 *  1. el CD del departamento de destino;
 *  2. si no, el CD más cercano a las coordenadas de entrega;
 *  3. si no, el CD del departamento inferido del prefijo de la referencia del pedido;
 *  4. si no, el CD por defecto configurado.
 * Null si la red no tiene ningún CD que cumpla (el envío queda sin origen resuelto).
 *
 * @phpstan-type DistributionCenter array{id: string, code: string, department: string|null, lat: float|null, lng: float|null}
 */
final readonly class OriginNodeSelector
{
    private const float EARTH_RADIUS_KM = 6371.0;

    /**
     * @param  list<DistributionCenter>  $centers            centros de distribución de la red
     * @param  array<string, string>     $prefixToDepartment prefijo de la referencia → código de departamento
     * @return DistributionCenter | null
     */
    public function select(
        array $centers,
        ?string $destinationDepartment,
        ?float $lat,
        ?float $lng,
        ?string $orderReference,
        array $prefixToDepartment,
        string $defaultCode,
    ): ?array {
        if ($centers === []) {
            return null;
        }

        if ($destinationDepartment !== null && $destinationDepartment !== '') {
            $center = $this->ofDepartment($centers, $destinationDepartment);
            if ($center !== null) {
                return $center;
            }
        }

        if ($lat !== null && $lng !== null) {
            $center = $this->nearest($centers, $lat, $lng);
            if ($center !== null) {
                return $center;
            }
        }

        if ($orderReference !== null && $orderReference !== '') {
            $department = $this->departmentFromReference($orderReference, $prefixToDepartment);
            $center = $department !== null ? $this->ofDepartment($centers, $department) : null;
            if ($center !== null) {
                return $center;
            }
        }

        foreach ($centers as $center) {
            if ($defaultCode !== '' && $center['code'] === $defaultCode) {
                return $center;
            }
        }

        return null;
    }

    /**
     * El CD más cercano a un punto (haversine), o null si ninguno tiene coordenadas.
     *
     * @param  list<DistributionCenter>  $centers
     * @return DistributionCenter | null
     */
    public function nearest(array $centers, float $lat, float $lng): ?array
    {
        $best = null;
        $bestDistance = INF;
        foreach ($centers as $center) {
            if ($center['lat'] === null || $center['lng'] === null) {
                continue;
            }

            $distance = $this->haversineKm($lat, $lng, $center['lat'], $center['lng']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $center;
            }
        }

        return $best;
    }

    /**
     * @param  list<DistributionCenter>  $centers
     * @return DistributionCenter | null
     */
    private function ofDepartment(array $centers, string $department): ?array
    {
        foreach ($centers as $center) {
            if ($center['department'] === $department) {
                return $center;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $prefixToDepartment
     */
    private function departmentFromReference(string $orderReference, array $prefixToDepartment): ?string
    {
        $upper = strtoupper($orderReference);
        // El prefijo más largo gana (CBBA antes que CBB), para no cortar un departamento en uno más corto.
        $prefixes = array_map(strval(...), array_keys($prefixToDepartment));
        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($upper, strtoupper($prefix))) {
                return $prefixToDepartment[$prefix];
            }
        }

        return null;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
