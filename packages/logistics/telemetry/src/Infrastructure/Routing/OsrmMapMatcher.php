<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Routing;

use Illuminate\Support\Facades\Http;
use Logistics\Telemetry\Domain\Contract\MapMatcher;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\MatchedSegment;
use Throwable;

/**
 * Map-matching contra OSRM (servicio /match): ajusta una traza GPS ruidosa a la red de calles real y, con
 * `annotations=speed,nodes`, devuelve por cada tramo los nodos OSM que lo delimitan y su velocidad. Vive en
 * Infrastructure: el dominio solo ve el contrato MapMatcher.
 *
 * Cada arista (par de nodos OSM consecutivos) se vuelve un MatchedSegment con clave estable (hash de los dos
 * nodos), de modo que la misma calle acumule muestras en el tiempo aunque las recorran distintos vehículos.
 *
 * Es I/O del worker de analítica, nunca de un request de usuario. Si OSRM no responde o la respuesta llega
 * mal formada, degrada a `[]`: la agregación masiva no debe reventar por un servicio externo caído.
 */
final readonly class OsrmMapMatcher implements MapMatcher
{
    public function match(array $points, array $timestampsSec): array
    {
        if (count($points) < 2) {
            return [];
        }

        try {
            return $this->fromOsrm($points, $timestampsSec);
        } catch (Throwable) {
            // OSRM caído o respuesta inesperada: se degrada a vacío para no cortar la agregación.
            return [];
        }
    }

    /**
     * @param  list<GeoPoint>       $points
     * @param  list<int>            $timestampsSec
     * @return list<MatchedSegment>
     */
    private function fromOsrm(array $points, array $timestampsSec): array
    {
        $host = rtrim((string) config('logistics-telemetry.streets.osrm_host'), '/');
        $timeout = (int) config('logistics-telemetry.streets.osrm_timeout', 10);

        $coords = implode(';', array_map(
            static fn (GeoPoint $p): string => $p->lng.','.$p->lat,
            $points,
        ));

        $url = $host.'/match/v1/driving/'.$coords;

        $query = [
            'geometries' => 'geojson',
            'overview' => 'full',
            'annotations' => 'speed,nodes',
            'steps' => 'false',
        ];
        if (count($timestampsSec) === count($points)) {
            $query['timestamps'] = implode(';', array_map(
                static fn (int $t): string => (string) $t,
                $timestampsSec,
            ));
        }

        $response = Http::timeout($timeout)->connectTimeout($timeout)->get($url, $query);

        if (!$response->ok()) {
            return [];
        }

        /** @var mixed $body */
        $body = $response->json();
        if (!is_array($body) || ($body['code'] ?? null) !== 'Ok') {
            return [];
        }

        $matchings = $body['matchings'] ?? null;
        if (!is_array($matchings)) {
            return [];
        }

        $hour = $this->hourOf($timestampsSec);

        $segments = [];
        foreach ($matchings as $matching) {
            if (!is_array($matching)) {
                continue;
            }
            foreach ($this->segmentsOf($matching, $hour) as $segment) {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * Convierte un `matching` de OSRM en sus aristas (tramos entre nodos OSM consecutivos).
     *
     * Reconstruye la secuencia completa de nodos y velocidades concatenando la anotación de cada leg (y
     * descartando el nodo fronterizo repetido entre legs), y la alinea con la geometría completa del
     * matching. Si algo no cuadra (datos faltantes o desalineados), devuelve `[]` para ese matching.
     *
     * @param  array<array-key, mixed> $matching
     * @return list<MatchedSegment>
     */
    private function segmentsOf(array $matching, int $hour): array
    {
        $geometry = $matching['geometry'] ?? null;
        $rawCoords = is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;
        $legs = $matching['legs'] ?? null;
        if (!is_array($rawCoords) || !is_array($legs)) {
            return [];
        }

        $coords = $this->parsePoints($rawCoords);

        /** @var list<int> $nodes */
        $nodes = [];
        /** @var list<float> $speeds */
        $speeds = [];
        foreach ($legs as $leg) {
            if (!is_array($leg)) {
                return [];
            }
            $annotation = $leg['annotation'] ?? null;
            if (!is_array($annotation)) {
                return [];
            }
            $legNodes = $annotation['nodes'] ?? null;
            $legSpeeds = $annotation['speed'] ?? null;
            if (!is_array($legNodes) || !is_array($legSpeeds)) {
                return [];
            }

            foreach (array_values($legNodes) as $node) {
                if (!is_numeric($node)) {
                    return [];
                }
                $nodeId = (int) $node;
                // El último nodo de un leg coincide con el primero del siguiente: no se duplica.
                if ($nodes !== [] && $nodes[count($nodes) - 1] === $nodeId) {
                    continue;
                }
                $nodes[] = $nodeId;
            }

            foreach (array_values($legSpeeds) as $speed) {
                if (!is_numeric($speed)) {
                    return [];
                }
                $speeds[] = (float) $speed;
            }
        }

        // Alineación esperada: tantos nodos como vértices de geometría, y una velocidad por arista.
        if (count($nodes) < 2 || count($coords) !== count($nodes) || count($speeds) !== count($nodes) - 1) {
            return [];
        }

        $segments = [];
        $edges = count($speeds);
        for ($i = 0; $i < $edges; $i++) {
            $speedMps = $speeds[$i];
            $a = $coords[$i];
            $b = $coords[$i + 1];
            $distance = $a->distanceMetersTo($b);
            $seconds = $speedMps > 0.0 ? (int) round($distance / $speedMps) : 0;

            $segments[] = MatchedSegment::of(
                sha1($nodes[$i].'-'.$nodes[$i + 1]),
                null,
                [$a->toArray(), $b->toArray()],
                round($speedMps * 3.6, 2),
                $seconds,
                $hour,
            );
        }

        return $segments;
    }

    /**
     * Parsea las coordenadas GeoJSON [lng, lat] a GeoPoint. Descarta pares mal formados.
     *
     * @param  array<array-key, mixed> $rawCoords
     * @return list<GeoPoint>
     */
    private function parsePoints(array $rawCoords): array
    {
        $points = [];
        foreach ($rawCoords as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                continue;
            }
            $points[] = GeoPoint::of((float) $pair[1], (float) $pair[0]);
        }

        return $points;
    }

    /**
     * Hora del día (0–23) representativa de la traza: la del primer punto. El histograma no necesita más
     * precisión; una traza contigua cae en una franja horaria estrecha.
     *
     * @param list<int> $timestampsSec
     */
    private function hourOf(array $timestampsSec): int
    {
        if ($timestampsSec === []) {
            return 0;
        }

        return (int) date('G', $timestampsSec[0]);
    }
}
