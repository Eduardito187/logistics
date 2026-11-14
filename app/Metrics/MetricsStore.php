<?php

declare(strict_types=1);

namespace App\Metrics;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Almacén de métricas en Valkey (Ola 5): contadores, histogramas y latidos, sin dependencias nuevas.
 *
 * Diseño:
 *   - Cada familia es UN hash de Valkey (`<prefijo>http:requests`, `<prefijo>http:duration`, ...). El campo
 *     del hash codifica la serie (valores de etiqueta unidos por `|`), así el scrape es un único `HGETALL`
 *     por familia y la escritura es un `HINCRBY`/`HINCRBYFLOAT` atómico.
 *   - Los histogramas guardan el conteo del bucket EXACTO donde cayó la observación (no acumulado) más la
 *     suma; el acumulado que exige Prometheus se calcula al renderizar. Así cada observación son dos
 *     incrementos y no nueve.
 *   - Toda escritura de un request viaja en UNA ida y vuelta (pipeline).
 *   - Tolerante: cualquier fallo de Valkey se traga (devuelve false / vacío). Una métrica jamás rompe el
 *     camino que instrumenta.
 *
 * Los hashes no llevan TTL: son contadores monótonos compartidos por todos los nodos. Si se borran (o se
 * reinicia Valkey sin persistencia) Prometheus lo ve como un reset de contador, que `rate()` ya tolera.
 */
final class MetricsStore
{
    /** Límites superiores (segundos) de los buckets de duración; el bucket `+Inf` es implícito. */
    public const BUCKETS = [0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /** Contador de requests HTTP: campo = `route|method|status|gate`. */
    public const HTTP_REQUESTS = 'http:requests';

    /** Histograma de duración HTTP: serie = `route|method|gate`. */
    public const HTTP_DURATION = 'http:duration';

    /** Histograma de duración de spans: serie = nombre del span. */
    public const SPAN_DURATION = 'span:duration';

    /** Hash de latidos: campo = nombre del latido, valor = timestamp Unix del último latido. */
    public const HEARTBEATS = 'heartbeat';

    private const SEPARATOR = '|';

    private const SUM_SUFFIX = '|sum';

    private const BUCKET_INFIX = '|le|';

    /**
     * Une valores de etiqueta en la clave de una serie. El separador se sanea en cada valor para que el
     * parseo al leer sea inequívoco.
     */
    public static function series(string ...$labels): string
    {
        return implode(self::SEPARATOR, array_map(
            static fn (string $label): string => str_replace(self::SEPARATOR, '_', $label),
            $labels,
        ));
    }

    /**
     * Inverso de {@see series()}: separa la clave en sus valores de etiqueta.
     *
     * @return list<string>
     */
    public static function labels(string $series): array
    {
        return explode(self::SEPARATOR, $series);
    }

    /**
     * Registra un request HTTP: +peso al contador y una observación al histograma, en un solo pipeline.
     *
     * @param int $weight peso de la muestra (1 sin muestreo; 1/sample_rate con muestreo, para no sesgar)
     */
    public function recordRequest(string $counterSeries, string $histogramSeries, float $seconds, int $weight = 1): bool
    {
        try {
            $requests = $this->key(self::HTTP_REQUESTS);
            $duration = $this->key(self::HTTP_DURATION);
            $bucket = $histogramSeries.self::BUCKET_INFIX.$this->bucketFor($seconds);
            $sum = $histogramSeries.self::SUM_SUFFIX;

            $this->connection()->pipeline(static function (mixed $pipe) use ($requests, $duration, $counterSeries, $bucket, $sum, $seconds, $weight): void {
                $pipe->hincrby($requests, $counterSeries, $weight);
                $pipe->hincrby($duration, $bucket, $weight);
                $pipe->hincrbyfloat($duration, $sum, $seconds * $weight);
            });

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Suma una observación a un histograma (una ida y vuelta).
     */
    public function observe(string $histogram, string $series, float $seconds): bool
    {
        try {
            $key = $this->key($histogram);
            $bucket = $series.self::BUCKET_INFIX.$this->bucketFor($seconds);
            $sum = $series.self::SUM_SUFFIX;

            $this->connection()->pipeline(static function (mixed $pipe) use ($key, $bucket, $sum, $seconds): void {
                $pipe->hincrby($key, $bucket, 1);
                $pipe->hincrbyfloat($key, $sum, $seconds);
            });

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Marca un latido (timestamp Unix actual) bajo el nombre dado.
     */
    public function beat(string $name): bool
    {
        try {
            $this->connection()->hset($this->key(self::HEARTBEATS), $name, (string) time());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Lee un hash de contadores.
     *
     * @return array<string, int> serie => valor
     */
    public function counters(string $family): array
    {
        $counters = [];

        foreach ($this->read($family) as $series => $value) {
            $counters[(string) $series] = (int) $value;
        }

        return $counters;
    }

    /**
     * Lee un histograma y lo agrupa por serie. Los buckets vienen SIN acumular, indexados por su `le`.
     *
     * @return array<string, array{buckets: array<int|string, int>, sum: float}>
     */
    public function histogram(string $family): array
    {
        $histogram = [];

        foreach ($this->read($family) as $field => $value) {
            $field = (string) $field;

            if (str_ends_with($field, self::SUM_SUFFIX)) {
                $series = substr($field, 0, -strlen(self::SUM_SUFFIX));
                $histogram[$series] ??= ['buckets' => [], 'sum' => 0.0];
                $histogram[$series]['sum'] = (float) $value;

                continue;
            }

            $position = strrpos($field, self::BUCKET_INFIX);
            if ($position === false) {
                continue;
            }

            $series = substr($field, 0, $position);
            $bound = substr($field, $position + strlen(self::BUCKET_INFIX));
            $histogram[$series] ??= ['buckets' => [], 'sum' => 0.0];
            $histogram[$series]['buckets'][$bound] = (int) $value;
        }

        return $histogram;
    }

    /**
     * Lee los latidos registrados.
     *
     * @return array<string, int> nombre => timestamp Unix del último latido
     */
    public function heartbeats(): array
    {
        return $this->counters(self::HEARTBEATS);
    }

    /**
     * Etiqueta `le` del bucket donde cae una duración.
     */
    private function bucketFor(float $seconds): string
    {
        foreach (self::BUCKETS as $bound) {
            if ($seconds <= $bound) {
                return PrometheusFormatter::boundLabel($bound);
            }
        }

        return PrometheusFormatter::INFINITY;
    }

    /**
     * `HGETALL` tolerante: vacío si Valkey no responde o la clave no existe.
     *
     * @return array<int|string, mixed>
     */
    private function read(string $family): array
    {
        try {
            $raw = $this->connection()->hgetall($this->key($family));

            return is_array($raw) ? $raw : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function key(string $family): string
    {
        return (string) config('observability.metrics.store.prefix', 'obs:').$family;
    }

    private function connection(): Connection
    {
        return Redis::connection((string) config('observability.metrics.store.connection', 'default'));
    }
}
