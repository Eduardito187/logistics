<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Routing;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpClient;
use Logistics\Planning\Domain\Contract\RoadMatrixProvider;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Throwable;

/**
 * Proveedor de la MATRIZ de distancias/tiempos por carretera sobre OSRM (servicio /table), con caché en
 * Valkey y degradación a haversine. Vive en Infrastructure: el dominio solo ve el contrato RoadMatrixProvider.
 *
 * Decisiones de infraestructura que viven acá, no en el dominio:
 *  - Caché (Valkey/Redis): la matriz de un MISMO conjunto de coordenadas no cambia; se cachea su FORMA
 *    PRIMITIVA (arrays de distancias y tiempos, nunca el objeto serializado — regla 7 de la guía), con
 *    clave derivada del perfil y de las coordenadas redondeadas a ~1 m. Reusos del día (reintentos,
 *    replanificación) no vuelven a pegarle al ruteador.
 *  - Lotes (chunking): /table tiene un tope de coordenadas por llamada. Para conjuntos grandes la matriz
 *    se arma por BLOQUES (submatrices fuente×destino) y se ensambla, de modo que escala a cientos de paradas.
 *  - Degradación: si OSRM no responde, tarda de más o devuelve algo no usable, se construye la matriz
 *    completa por haversine (línea recta + velocidad media), para que la generación del plan nunca se caiga.
 */
final readonly class OsrmRoadMatrixProvider implements RoadMatrixProvider
{
    /** Prefijo de las claves de caché de matrices. */
    private const CACHE_PREFIX = 'planning:osrm:matrix:';

    public function __construct(
        private HttpClient $http,
        private Cache $cache,
        private string $baseUrl,
        private int $timeout,
        private int $maxTableCoordinates,
        private int $cacheTtl,
        private float $fallbackSpeedKmh,
        private string $profile = 'driving',
    ) {}

    public function matrixFor(array $points): DistanceMatrix
    {
        $points = array_values($points);
        $size = count($points);
        if ($size === 0) {
            // Matriz 1x1 degenerada: evita construir algo vacío aguas arriba.
            return DistanceMatrix::fromArrays([[0.0]], [[0.0]]);
        }
        if ($size === 1) {
            return DistanceMatrix::fromArrays([[0.0]], [[0.0]]);
        }

        $cacheKey = $this->cacheKey($points);
        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $matrix = $this->compute($points);
        $this->writeCache($cacheKey, $matrix);

        return $matrix;
    }

    /**
     * @param list<GeoPoint> $points
     */
    private function compute(array $points): DistanceMatrix
    {
        try {
            [$distances, $durations] = $this->fromOsrm($points);
        } catch (Throwable) {
            return $this->haversineMatrix($points);
        }

        return DistanceMatrix::fromArrays($distances, $durations);
    }

    /**
     * Arma la matriz consultando OSRM /table, por bloques si el conjunto supera el tope por llamada.
     *
     * @param  list<GeoPoint>                                    $points
     * @return array{0: list<list<float>>, 1: list<list<float>>}
     */
    private function fromOsrm(array $points): array
    {
        $size = count($points);
        $distances = $this->zeroGrid($size);
        $durations = $this->zeroGrid($size);

        // Si todo el conjunto entra en una llamada, un solo bloque; si no, bloques de la mitad del tope,
        // para que la unión fuente+destino de cada par de bloques nunca supere el tope por llamada. El
        // max(1, ...) garantiza un tamaño de bloque >= 1 (array_chunk exige una longitud positiva).
        $blockSize = max(1, $size <= $this->maxTableCoordinates
            ? $size
            : intdiv($this->maxTableCoordinates, 2));
        $blocks = array_chunk(range(0, $size - 1), $blockSize);

        foreach ($blocks as $rowBlock) {
            foreach ($blocks as $colBlock) {
                $this->fillBlock($points, $rowBlock, $colBlock, $distances, $durations);
            }
        }

        // Se reconstruyen como list<list<float>> genuinas: las escrituras por índice global degradan el
        // tipo a array<int, ...> para el analizador, aunque la rejilla esté completa y sea densa.
        return [$this->toDenseGrid($distances), $this->toDenseGrid($durations)];
    }

    /**
     * Normaliza una rejilla densa (índices 0..n completos) a una list<list<float>> reindexada, para que
     * encaje con el contrato de DistanceMatrix sin perder valores ni orden.
     *
     * @param  array<int, array<int, float>> $grid
     * @return list<list<float>>
     */
    private function toDenseGrid(array $grid): array
    {
        return array_map(
            static fn (array $row): array => array_values($row),
            array_values($grid),
        );
    }

    /**
     * Llena las celdas (fila×columna) de un par de bloques con una sola llamada a /table, mapeando los
     * índices locales de la respuesta a los índices globales de la matriz. Donde OSRM no sabe rutear (celda
     * null), se rellena con la estimación haversine para no dejar huecos.
     *
     * @param list<GeoPoint>                $points
     * @param list<int>                     $rowBlock  índices globales de las fuentes
     * @param list<int>                     $colBlock  índices globales de los destinos
     * @param array<int, array<int, float>> $distances rejilla completa (índices globales), se muta por referencia
     * @param array<int, array<int, float>> $durations rejilla completa (índices globales), se muta por referencia
     */
    private function fillBlock(array $points, array $rowBlock, array $colBlock, array &$distances, array &$durations): void
    {
        $sameBlock = $rowBlock === $colBlock;

        if ($sameBlock) {
            $coords = array_map(static fn (int $i): GeoPoint => $points[$i], $rowBlock);
            $query = ['annotations' => 'distance,duration'];
        } else {
            $coords = array_merge(
                array_map(static fn (int $i): GeoPoint => $points[$i], $rowBlock),
                array_map(static fn (int $i): GeoPoint => $points[$i], $colBlock),
            );
            $query = [
                'annotations' => 'distance,duration',
                'sources' => implode(';', range(0, count($rowBlock) - 1)),
                'destinations' => implode(';', range(count($rowBlock), count($rowBlock) + count($colBlock) - 1)),
            ];
        }

        $body = $this->callTable($coords, $query);

        $respDistances = $body['distances'] ?? null;
        $respDurations = $body['durations'] ?? null;
        if (!is_array($respDistances) || !is_array($respDurations)) {
            throw new \RuntimeException('respuesta de /table sin matrices');
        }

        foreach ($rowBlock as $localRow => $globalRow) {
            foreach ($colBlock as $localCol => $globalCol) {
                $distance = $respDistances[$localRow][$localCol] ?? null;
                $duration = $respDurations[$localRow][$localCol] ?? null;

                $distances[$globalRow][$globalCol] = is_numeric($distance)
                    ? (float) $distance
                    : $points[$globalRow]->haversineMeters($points[$globalCol]);
                $durations[$globalRow][$globalCol] = is_numeric($duration)
                    ? (float) $duration
                    : $this->fallbackSeconds($points[$globalRow], $points[$globalCol]);
            }
        }
    }

    /**
     * Ejecuta una llamada a /table y devuelve el cuerpo decodificado, o lanza si no es usable (dispara la
     * degradación a haversine aguas arriba).
     *
     * @param  list<GeoPoint>        $coords
     * @param  array<string, string> $query
     * @return array<string, mixed>
     */
    private function callTable(array $coords, array $query): array
    {
        $path = implode(';', array_map(
            static fn (GeoPoint $p): string => $p->longitude.','.$p->latitude,
            $coords,
        ));

        $url = rtrim($this->baseUrl, '/')."/table/v1/{$this->profile}/{$path}";

        $response = $this->http->timeout($this->timeout)
            ->connectTimeout($this->timeout)
            ->get($url, $query);

        if (!$response->ok()) {
            throw new \RuntimeException('OSRM /table respondió con error HTTP');
        }

        /** @var mixed $body */
        $body = $response->json();
        if (!is_array($body) || ($body['code'] ?? null) !== 'Ok') {
            throw new \RuntimeException('OSRM /table respondió un código no Ok');
        }

        return $body;
    }

    /**
     * Matriz completa por haversine (línea recta) cuando el ruteador no está disponible.
     *
     * @param list<GeoPoint> $points
     */
    private function haversineMatrix(array $points): DistanceMatrix
    {
        $size = count($points);
        $distances = $this->zeroGrid($size);
        $durations = $this->zeroGrid($size);

        foreach ($points as $i => $from) {
            foreach ($points as $j => $to) {
                if ($i === $j) {
                    continue;
                }
                $distances[$i][$j] = $from->haversineMeters($to);
                $durations[$i][$j] = $this->fallbackSeconds($from, $to);
            }
        }

        return DistanceMatrix::fromArrays($this->toDenseGrid($distances), $this->toDenseGrid($durations));
    }

    private function fallbackSeconds(GeoPoint $from, GeoPoint $to): float
    {
        $speedMetersPerSecond = max(1.0, $this->fallbackSpeedKmh) * 1000.0 / 3600.0;

        return $from->haversineMeters($to) / $speedMetersPerSecond;
    }

    /**
     * @return list<list<float>>
     */
    private function zeroGrid(int $size): array
    {
        return array_fill(0, $size, array_fill(0, $size, 0.0));
    }

    /**
     * @param list<GeoPoint> $points
     */
    private function cacheKey(array $points): string
    {
        $fingerprint = $this->profile.'|'.implode(';', array_map(
            static fn (GeoPoint $p): string => $p->cacheKey(),
            $points,
        ));

        return self::CACHE_PREFIX.sha1($fingerprint);
    }

    private function readCache(string $key): ?DistanceMatrix
    {
        try {
            /** @var mixed $cached */
            $cached = $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($cached) || !isset($cached['distances'], $cached['durations'])) {
            return null;
        }

        try {
            /** @var list<list<float>> $distances */
            $distances = $cached['distances'];
            /** @var list<list<float>> $durations */
            $durations = $cached['durations'];

            return DistanceMatrix::fromArrays($distances, $durations);
        } catch (Throwable) {
            return null;
        }
    }

    private function writeCache(string $key, DistanceMatrix $matrix): void
    {
        if ($this->cacheTtl <= 0) {
            return;
        }

        try {
            // Se cachea la FORMA PRIMITIVA (arrays), no el objeto de dominio (regla 7 de la guía).
            $this->cache->put($key, [
                'distances' => $matrix->distances,
                'durations' => $matrix->durations,
            ], $this->cacheTtl);
        } catch (Throwable) {
            // Un fallo de caché nunca debe tumbar la planificación: se ignora.
        }
    }
}
