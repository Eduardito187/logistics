<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Foto de las medidas POR UNIDAD de producto de una línea de envío (Roadmap 02, Fase 2.1): peso (kg),
 * volumen (m³) y la fuente de la que salieron (producto / categoría / default global).
 *
 * Se toma UNA vez al recibir el envío y no se recalcula después: si el catálogo cambia, el envío conserva
 * las medidas con las que entró (inmutable desde la recepción). Siempre existe un valor (con default)
 * para que planificación nunca se bloquee por falta de medidas; la fuente `default` marca el dato
 * incompleto.
 */
final readonly class LineMeasure
{
    private const WEIGHT_PRECISION = 3;

    private const VOLUME_PRECISION = 6;

    private function __construct(
        public float $unitWeightKg,
        public float $unitVolumeM3,
        public MeasurementsSource $source,
    ) {}

    public static function of(float $unitWeightKg, float $unitVolumeM3, MeasurementsSource $source): self
    {
        if (!is_finite($unitWeightKg) || $unitWeightKg <= 0.0) {
            throw ShipmentsException::because("el peso unitario de la línea debe ser > 0: {$unitWeightKg}");
        }
        if (!is_finite($unitVolumeM3) || $unitVolumeM3 <= 0.0) {
            throw ShipmentsException::because("el volumen unitario de la línea debe ser > 0: {$unitVolumeM3}");
        }

        $weight = round($unitWeightKg, self::WEIGHT_PRECISION);
        $volume = round($unitVolumeM3, self::VOLUME_PRECISION);

        // Valores positivos que redondean a 0 con la precisión de persistencia se llevan al mínimo
        // representable, para no persistir un 0 inválido.
        return new self(
            $weight > 0.0 ? $weight : 0.001,
            $volume > 0.0 ? $volume : 0.000001,
            $source,
        );
    }

    /**
     * True si algún valor cayó al default global (dato logístico incompleto).
     */
    public function isDefaulted(): bool
    {
        return $this->source === MeasurementsSource::Default;
    }

    /**
     * Peso total de la línea (kg) para una cantidad dada.
     */
    public function totalWeightKg(int $quantity): float
    {
        return round($this->unitWeightKg * $quantity, self::WEIGHT_PRECISION);
    }

    /**
     * Volumen total de la línea (m³) para una cantidad dada.
     */
    public function totalVolumeM3(int $quantity): float
    {
        return round($this->unitVolumeM3 * $quantity, self::VOLUME_PRECISION);
    }
}
