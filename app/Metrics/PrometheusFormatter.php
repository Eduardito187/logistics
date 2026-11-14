<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Formateador mínimo del formato de texto de exposición de Prometheus (versión 0.0.4).
 *
 * Se implementa a mano, sin dependencias externas (promphp/prometheus_client_php u otras), porque
 * las señales que exponemos son contadores/gauges leídos por consulta ligera e histogramas de buckets
 * fijos y cardinalidad acotada. Si en el futuro se necesitara un registro con labels dinámicos de alta
 * cardinalidad, se puede cambiar por una librería sin tocar al consumidor (el collector arma la lista
 * y delega el render aquí).
 *
 * Contrato del formato: por cada métrica una línea `# HELP`, una línea `# TYPE` y una o más muestras
 * `nombre{etiqueta="valor"} numero`. Las etiquetas se escapan según la especificación. Los histogramas
 * emiten `_bucket{le}` acumulativos, `_sum` y `_count` (ver {@see histogram()}).
 */
final class PrometheusFormatter
{
    /** Etiqueta `le` del bucket implícito que contiene todas las observaciones. */
    public const INFINITY = '+Inf';

    /** @var list<string> Bloques ya renderizados, uno por métrica. */
    private array $blocks = [];

    /**
     * Agrega una métrica con una o más muestras.
     *
     * @param 'gauge'|'counter'                                                 $type
     * @param list<array{labels?: array<string, string|int>, value: int|float}> $samples
     */
    public function metric(string $name, string $type, string $help, array $samples): self
    {
        $lines = [
            '# HELP '.$name.' '.$help,
            '# TYPE '.$name.' '.$type,
        ];

        foreach ($samples as $sample) {
            $labels = $this->renderLabels($sample['labels'] ?? []);
            $lines[] = $name.$labels.' '.$this->renderValue($sample['value']);
        }

        $this->blocks[] = implode("\n", $lines);

        return $this;
    }

    /**
     * Atajo para una métrica de un solo valor sin etiquetas.
     *
     * @param 'gauge'|'counter' $type
     */
    public function scalar(string $name, string $type, string $help, int | float $value): self
    {
        return $this->metric($name, $type, $help, [['value' => $value]]);
    }

    /**
     * Agrega un histograma Prometheus. Recibe los buckets SIN acumular (conteo de observaciones que
     * cayeron en cada tramo, indexado por su etiqueta `le`) y emite lo que exige el formato: una línea
     * `_bucket{le="..."}` ACUMULATIVA por límite, el `le="+Inf"` con el total, `_sum` y `_count`.
     *
     * El total (`+Inf` y `_count`) es la suma de todos los buckets recibidos, incluidos los que no
     * coincidan con `$bounds` (p. ej. datos viejos tras cambiar los límites): no se pierde ninguna muestra.
     *
     * @param list<float>                                                                                      $bounds límites superiores en orden ascendente (sin +Inf)
     * @param list<array{labels?: array<string, string|int>, buckets: array<int|string, int>, sum: int|float}> $series
     */
    public function histogram(string $name, string $help, array $bounds, array $series): self
    {
        $lines = [
            '# HELP '.$name.' '.$help,
            '# TYPE '.$name.' histogram',
        ];

        foreach ($series as $entry) {
            $labels = $entry['labels'] ?? [];
            $cumulative = 0;

            foreach ($bounds as $bound) {
                $label = self::boundLabel($bound);
                $cumulative += $entry['buckets'][$label] ?? 0;
                $lines[] = $name.'_bucket'.$this->renderLabels([...$labels, 'le' => $label]).' '.$cumulative;
            }

            $total = array_sum($entry['buckets']);
            $lines[] = $name.'_bucket'.$this->renderLabels([...$labels, 'le' => self::INFINITY]).' '.$total;
            $lines[] = $name.'_sum'.$this->renderLabels($labels).' '.$this->renderValue($entry['sum']);
            $lines[] = $name.'_count'.$this->renderLabels($labels).' '.$total;
        }

        $this->blocks[] = implode("\n", $lines);

        return $this;
    }

    /**
     * Etiqueta `le` canónica de un límite de bucket (`0.05`, `1`, `2.5`, `10`). Es la misma cadena que
     * usa el almacén como sufijo del campo, así escritura y render nunca se desalinean.
     */
    public static function boundLabel(float $bound): string
    {
        return (string) $bound;
    }

    /**
     * Render final: bloques separados por línea en blanco y salto final (exigido por el formato).
     */
    public function render(): string
    {
        return implode("\n\n", $this->blocks)."\n";
    }

    /**
     * @param array<string, string|int> $labels
     */
    private function renderLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key.'="'.$this->escapeLabelValue((string) $value).'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    private function renderValue(int | float $value): string
    {
        // Enteros sin notación científica; flotantes con precisión suficiente sin ceros colgando.
        if (is_int($value) || $value === floor($value)) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }

    /**
     * Escapa `\`, `"` y salto de línea en el valor de la etiqueta (especificación de Prometheus).
     */
    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
