<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Routing;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Logistics\Planning\Domain\Contract\OptimizedStop;
use Logistics\Planning\Domain\Contract\RouteOptimizer;
use Logistics\Planning\Domain\Contract\RouteStop;
use Logistics\Planning\Domain\ValueObject\Eta;
use Throwable;

/**
 * Motor de rutas real sobre OSRM (Open Source Routing Machine), el ruteador de OpenStreetMap.
 *
 * Usa el servicio /trip de OSRM: resuelve el orden óptimo de reparto (un TSP sobre calles reales) y
 * devuelve la duración de cada tramo, con la que se arma la ETA acumulada de cada parada desde la
 * salida. El orden de la lista devuelta ES la secuencia de reparto propuesta.
 *
 * Degrada con gracia: si OSRM no responde, tarda de más, devuelve un código distinto de "Ok" o manda
 * una respuesta que no calza con las paradas pedidas, cae a un orden por VECINO MÁS CERCANO (heurística
 * del TSP sobre distancia haversine, arrancando por la primera parada / CD) con ETA secuencial fija.
 * Así, aun sin ruteador, nunca queda un recorrido absurdo tipo La Paz → Santa Cruz → La Paz: siempre
 * encadena la parada pendiente más próxima. Nunca rompe la generación del plan por un fallo del ruteador.
 *
 * La geometría de la ruta por calles (polilínea) la pide directamente el mapa del frente de Ejecución
 * contra OSRM; el contrato de dominio solo transporta orden y ETA, así que acá no se arrastra.
 */
final readonly class OsrmRouteOptimizer implements RouteOptimizer
{
    /** Minutos de viaje asumidos entre paradas consecutivas cuando se cae al orden de entrada. */
    private const FALLBACK_MINUTES_PER_LEG = 20;

    public function __construct(
        private string $baseUrl,
        private int $timeout,
    ) {}

    public function optimize(DateTimeImmutable $departAt, array $stops): array
    {
        // Con menos de dos paradas no hay nada que optimizar ni tramos que medir: orden de entrada.
        if (count($stops) < 2) {
            return $this->fallback($departAt, $stops);
        }

        try {
            $optimized = $this->optimizeWithOsrm($departAt, $stops);
        } catch (Throwable) {
            // Cualquier fallo de red, timeout o respuesta inesperada: se degrada al orden de entrada.
            return $this->fallback($departAt, $stops);
        }

        return $optimized ?? $this->fallback($departAt, $stops);
    }

    /**
     * Consulta el servicio /trip de OSRM y arma las paradas optimizadas con su ETA real.
     *
     * @param  list<RouteStop>          $stops
     * @return list<OptimizedStop>|null null si la respuesta no es usable (dispara el fallback)
     */
    private function optimizeWithOsrm(DateTimeImmutable $departAt, array $stops): ?array
    {
        $coordinates = implode(';', array_map(
            static fn (RouteStop $stop): string => $stop->longitude.','.$stop->latitude,
            $stops,
        ));

        $url = rtrim($this->baseUrl, '/')."/trip/v1/driving/{$coordinates}";

        $response = Http::timeout($this->timeout)
            ->connectTimeout($this->timeout)
            ->get($url, [
                // Ruta abierta: arranca en la primera parada y termina en la última (combinación de
                // source/destination soportada por OSRM con roundtrip=false); no vuelve al inicio.
                'source' => 'first',
                'destination' => 'last',
                'roundtrip' => 'false',
                // La geometría no se usa en el backend (la dibuja el mapa del front), se omite.
                'overview' => 'false',
            ]);

        if (!$response->ok()) {
            return null;
        }

        /** @var mixed $body */
        $body = $response->json();
        if (!is_array($body) || ($body['code'] ?? null) !== 'Ok') {
            return null;
        }

        $order = $this->resolveDeliveryOrder($body['waypoints'] ?? null, count($stops));
        $legSeconds = $this->resolveLegSeconds($body['trips'] ?? null);
        if ($order === null || $legSeconds === null) {
            return null;
        }

        // Duración acumulada hasta cada posición del recorrido: la posición 0 es el arranque (0s) y
        // cada posición suma el tramo que la precede.
        $optimized = [];
        $cumulativeSeconds = 0;
        foreach ($order as $tripPosition => $stopIndex) {
            if ($tripPosition > 0) {
                $cumulativeSeconds += $legSeconds[$tripPosition - 1] ?? 0;
            }
            $minutes = (int) round($cumulativeSeconds / 60);
            $arrivalAt = $departAt->modify("+{$minutes} minutes");
            $optimized[] = new OptimizedStop($stops[$stopIndex]->shipmentId, Eta::at($arrivalAt, $minutes));
        }

        return $optimized;
    }

    /**
     * Traduce los waypoints de OSRM a la secuencia de reparto: posición en el recorrido → índice de la
     * parada de entrada. Los waypoints vienen en el mismo orden que las coordenadas enviadas, y cada uno
     * trae su `waypoint_index` (su lugar dentro del recorrido óptimo).
     *
     * @return array<int, int>|null mapa posición→índice, o null si la respuesta no calza
     */
    private function resolveDeliveryOrder(mixed $waypoints, int $expected): ?array
    {
        if (!is_array($waypoints) || count($waypoints) !== $expected) {
            return null;
        }

        $order = [];
        foreach ($waypoints as $stopIndex => $waypoint) {
            if (!is_int($stopIndex) || !is_array($waypoint)) {
                return null;
            }
            $position = $waypoint['waypoint_index'] ?? null;
            if (!is_int($position)) {
                return null;
            }
            $order[$position] = $stopIndex;
        }

        // Debe ser una permutación completa de 0..n-1; si falta o se repite una posición, no sirve.
        ksort($order);
        if (array_keys($order) !== range(0, $expected - 1)) {
            return null;
        }

        return $order;
    }

    /**
     * Extrae la duración (en segundos) de cada tramo del primer recorrido devuelto por OSRM.
     *
     * @return array<int, float>|null duraciones por tramo, o null si la respuesta no es usable
     */
    private function resolveLegSeconds(mixed $trips): ?array
    {
        if (!is_array($trips) || !isset($trips[0]) || !is_array($trips[0])) {
            return null;
        }

        $legs = $trips[0]['legs'] ?? null;
        if (!is_array($legs)) {
            return null;
        }

        $seconds = [];
        foreach ($legs as $leg) {
            if (!is_array($leg)) {
                return null;
            }
            $duration = $leg['duration'] ?? null;
            if (!is_int($duration) && !is_float($duration)) {
                return null;
            }
            $seconds[] = (float) $duration;
        }

        return $seconds;
    }

    /**
     * Red de seguridad cuando OSRM no está disponible: reordena las paradas por VECINO MÁS CERCANO y les
     * asigna una ETA secuencial fija. Reordenar (en vez de respetar el orden crudo de entrada) evita que
     * el plan quede con un recorrido absurdo —ida y vuelta entre zonas lejanas— solo porque el ruteador
     * no respondió.
     *
     * @param  list<RouteStop>     $stops
     * @return list<OptimizedStop>
     */
    private function fallback(DateTimeImmutable $departAt, array $stops): array
    {
        $ordered = $this->nearestNeighborOrder($stops);

        $optimized = [];
        $minutes = 0;
        foreach ($ordered as $stop) {
            $minutes += self::FALLBACK_MINUTES_PER_LEG;
            $arrivalAt = $departAt->modify("+{$minutes} minutes");
            $optimized[] = new OptimizedStop($stop->shipmentId, Eta::at($arrivalAt, $minutes));
        }

        return $optimized;
    }

    /**
     * Ordena las paradas por vecino más cercano (heurística clásica del TSP): arranca por la PRIMERA
     * parada de la lista —el punto de salida / CD— y encadena siempre la parada aún no visitada más
     * próxima a la última elegida, midiendo distancia en línea recta con la fórmula de haversine. No es
     * óptimo como OSRM, pero produce un recorrido razonable y nunca el absurdo "La Paz → Santa Cruz →
     * La Paz". Con menos de tres paradas no hay nada que reordenar: se devuelve el orden de entrada.
     *
     * @param  list<RouteStop> $stops
     * @return list<RouteStop>
     */
    private function nearestNeighborOrder(array $stops): array
    {
        if (count($stops) < 3) {
            return $stops;
        }

        $remaining = $stops;
        // Arranca en la primera parada (salida / CD) y la quita de las pendientes.
        $current = array_shift($remaining);
        if ($current === null) {
            return $stops;
        }

        $ordered = [$current];
        while ($remaining !== []) {
            $bestKey = null;
            $bestDistance = INF;
            foreach ($remaining as $key => $candidate) {
                $distance = $this->haversineKm($current, $candidate);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestKey = $key;
                }
            }

            if ($bestKey === null) {
                break;
            }

            $current = $remaining[$bestKey];
            $ordered[] = $current;
            // Se reconstruye sin la parada elegida (en vez de unset) para que el análisis estático vea
            // que las pendientes pueden quedar vacías y el bucle termina.
            $remaining = array_filter(
                $remaining,
                static fn (int $key): bool => $key !== $bestKey,
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $ordered;
    }

    /**
     * Distancia en kilómetros entre dos paradas por la fórmula de haversine (gran círculo). Alcanza para
     * comparar cercanía entre paradas y decidir el siguiente salto del recorrido.
     */
    private function haversineKm(RouteStop $a, RouteStop $b): float
    {
        $earthRadiusKm = 6371.0;

        $deltaLat = deg2rad($b->latitude - $a->latitude);
        $deltaLng = deg2rad($b->longitude - $a->longitude);
        $lat1 = deg2rad($a->latitude);
        $lat2 = deg2rad($b->latitude);

        $h = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return 2 * $earthRadiusKm * asin(min(1.0, sqrt($h)));
    }
}
