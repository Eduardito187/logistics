<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Formateador mínimo del formato de texto de exposición de Prometheus (versión 0.0.4).
 *
 * Se implementa a mano, sin dependencias externas (promphp/prometheus_client_php u otras), porque
 * las señales que exponemos son contadores/gauges simples leídos por consulta ligera. Si en el futuro
 * se necesitara un registro con histogramas/labels dinámicos de alta cardinalidad, se puede cambiar
 * por una librería sin tocar al consumidor (el controlador arma la lista y delega el render aquí).
 *
 * Contrato del formato: por cada métrica una línea `# HELP`, una línea `# TYPE` y una o más muestras
 * `nombre{etiqueta="valor"} numero`. Las etiquetas se escapan según la especificación.
 */
final class PrometheusFormatter
{
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
