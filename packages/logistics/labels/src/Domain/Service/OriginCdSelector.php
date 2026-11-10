<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Service;

/**
 * Selecciona el CÓDIGO del nodo CD (centro de distribución) de ORIGEN de un envío, para el campo `org`
 * del QR. Dominio PURO: recibe primitivos (la lista de CDs de la red y los datos del envío) y aplica la
 * regla de negocio, sin tocar Laravel ni la base.
 *
 * El VALOR del `org` NO es contrato con la app Flutter (solo el formato canónico firmado lo es): esta
 * regla lo FIJA de forma estable y determinista. Orden de preferencia:
 *  1. Si el envío va a un nodo con departamento conocido → el CD de ese departamento.
 *  2. Si no (domicilio) pero hay coordenadas → el CD MÁS CERCANO por haversine.
 *  3. Si no hay coordenadas → el CD del departamento inferido del prefijo de la referencia del pedido.
 *  4. Si nada resuelve → el código por defecto configurado.
 */
final readonly class OriginCdSelector
{
    private const float EARTH_RADIUS_KM = 6371.0;

    /**
     * @param  list<array{code: string, department: string, lat: float|null, lng: float|null}> $cds                centros de distribución de la red
     * @param  array<string, string>                                                           $prefixToDepartment prefijo de la referencia (mayúsculas) → departamento
     * @return string                                                                          código del CD de origen; '' solo si no hay CDs ni default
     */
    public function select(
        array $cds,
        ?string $destinationDepartment,
        ?float $lat,
        ?float $lng,
        ?string $orderReference,
        array $prefixToDepartment,
        string $defaultCode,
    ): string {
        if ($cds === []) {
            return $defaultCode;
        }

        // 1. Departamento del nodo destino (retiro en tienda / nodo).
        if ($destinationDepartment !== null && $destinationDepartment !== '') {
            $code = $this->cdForDepartment($cds, $destinationDepartment);
            if ($code !== '') {
                return $code;
            }
        }

        // 2. Domicilio con coordenadas: el CD más cercano.
        if ($lat !== null && $lng !== null) {
            $code = $this->nearestCd($cds, $lat, $lng);
            if ($code !== '') {
                return $code;
            }
        }

        // 3. Sin coordenadas: departamento inferido del prefijo de la referencia del pedido.
        if ($orderReference !== null && $orderReference !== '') {
            $department = $this->departmentFromReference($orderReference, $prefixToDepartment);
            if ($department !== '') {
                $code = $this->cdForDepartment($cds, $department);
                if ($code !== '') {
                    return $code;
                }
            }
        }

        // 4. Último recurso: el CD por defecto configurado.
        return $defaultCode;
    }

    /** @param list<array{code: string, department: string, lat: float|null, lng: float|null}> $cds */
    private function cdForDepartment(array $cds, string $department): string
    {
        foreach ($cds as $cd) {
            if ($cd['department'] === $department) {
                return $cd['code'];
            }
        }

        return '';
    }

    /** @param list<array{code: string, department: string, lat: float|null, lng: float|null}> $cds */
    private function nearestCd(array $cds, float $lat, float $lng): string
    {
        $best = '';
        $bestDistance = INF;
        foreach ($cds as $cd) {
            if ($cd['lat'] === null || $cd['lng'] === null) {
                continue;
            }
            $distance = $this->haversineKm($lat, $lng, $cd['lat'], $cd['lng']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $cd['code'];
            }
        }

        return $best;
    }

    /** @param array<string, string> $prefixToDepartment */
    private function departmentFromReference(string $orderReference, array $prefixToDepartment): string
    {
        $upper = strtoupper($orderReference);
        // El prefijo más largo gana (CBBA antes que CBB), para no cortar un departamento en uno más corto.
        $prefixes = array_keys($prefixToDepartment);
        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($upper, strtoupper($prefix))) {
                return $prefixToDepartment[$prefix];
            }
        }

        return '';
    }

    /** Distancia en kilómetros entre dos puntos geográficos (haversine). */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
