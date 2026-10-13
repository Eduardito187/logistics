<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

use Logistics\Telemetry\Domain\Exception\TelemetryException;

/**
 * Polígono geográfico de una geocerca, como anillo cerrado de puntos (lat/lng).
 *
 * El repo NO usa PostGIS: la geocerca se guarda como lista JSON de [lat,lng] y la prueba de
 * pertenencia (¿este punto cae dentro?) se resuelve en PHP puro con ray casting (algoritmo de
 * cruce de rayos par/impar). Suficiente para zonas operativas sin depender de la base.
 *
 * El anillo se almacena cerrado (primer punto == último). Un polígono necesita al menos 3 vértices
 * distintos para encerrar un área.
 */
final readonly class Polygon
{
    /**
     * @param list<GeoPoint> $vertices anillo cerrado (el primer punto se repite al final)
     */
    private function __construct(
        public array $vertices,
    ) {}

    /**
     * Construye el polígono a partir de sus puntos. Exige un mínimo de 3 y cierra el anillo si hace falta.
     *
     * @param list<GeoPoint> $points
     */
    public static function fromPoints(array $points): self
    {
        $points = array_values($points);
        if (count($points) < 3) {
            throw TelemetryException::because('Un polígono de geocerca requiere al menos 3 puntos.');
        }

        $first = $points[0];
        $last = $points[count($points) - 1];
        if (!$first->equals($last)) {
            $points[] = $first;
        }

        return new self($points);
    }

    /**
     * Construye el polígono desde su forma primitiva (lista de pares {lat,lng}).
     *
     * @param list<array{lat: float, lng: float}> $points
     */
    public static function fromArray(array $points): self
    {
        return self::fromPoints(array_map(
            static fn (array $point): GeoPoint => GeoPoint::of($point['lat'], $point['lng']),
            array_values($points),
        ));
    }

    /**
     * ¿El punto cae dentro del polígono? Ray casting: se traza un rayo horizontal hacia el este y se
     * cuentan los cruces con las aristas; impar = dentro, par = fuera. Puro, sin dependencias.
     */
    public function contains(GeoPoint $point): bool
    {
        $inside = false;
        $count = count($this->vertices);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = $this->vertices[$i]->lat;
            $lngI = $this->vertices[$i]->lng;
            $latJ = $this->vertices[$j]->lat;
            $lngJ = $this->vertices[$j]->lng;

            $intersects = (($latI > $point->lat) !== ($latJ > $point->lat))
                && ($point->lng < ($lngJ - $lngI) * ($point->lat - $latI) / ($latJ - $latI) + $lngI);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Forma primitiva para persistir/serializar (lista de pares {lat,lng}).
     *
     * @return list<array{lat: float, lng: float}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (GeoPoint $vertex): array => $vertex->toArray(),
            $this->vertices,
        );
    }
}
