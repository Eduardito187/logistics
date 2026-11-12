<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\ValueObject;

use Commerce\Catalog\Domain\Exception\CatalogException;

/**
 * Medidas físicas de un producto (o los valores por defecto de una categoría): peso en kg y largo,
 * ancho y alto en cm (Roadmap 02, Fase 2.0).
 *
 * Todos los campos son OPCIONALES: se admite una ficha parcial (p. ej. solo el peso) porque las medidas
 * llegan incompletas desde el admin o desde Magento/integraciones. Las medidas faltantes nunca bloquean
 * nada: aguas abajo se cae a la categoría o al default global y se marca la fuente.
 *
 * Reglas: cada valor presente debe ser finito, > 0 y no superar el máximo razonable. Los valores se
 * redondean a la precisión de persistencia (peso 3 decimales, medidas 2) para que dominio y base
 * coincidan. El volumen (m³) solo existe cuando están las tres dimensiones: L × A × H / 1 000 000.
 */
final readonly class PhysicalSpec
{
    /** Peso máximo aceptado por unidad de producto (kg). */
    public const MAX_WEIGHT_KG = 20000.0;

    /** Dimensión máxima aceptada por lado (cm). */
    public const MAX_DIMENSION_CM = 2000.0;

    private const WEIGHT_PRECISION = 3;

    private const DIMENSION_PRECISION = 2;

    private const VOLUME_PRECISION = 6;

    private function __construct(
        public ?float $weightKg,
        public ?float $lengthCm,
        public ?float $widthCm,
        public ?float $heightCm,
    ) {}

    /**
     * Construye una ficha validando rangos. Cualquier campo puede ser null (ficha parcial).
     */
    public static function of(
        ?float $weightKg = null,
        ?float $lengthCm = null,
        ?float $widthCm = null,
        ?float $heightCm = null,
    ): self {
        return new self(
            self::validated($weightKg, 'peso (kg)', self::MAX_WEIGHT_KG, self::WEIGHT_PRECISION),
            self::validated($lengthCm, 'largo (cm)', self::MAX_DIMENSION_CM, self::DIMENSION_PRECISION),
            self::validated($widthCm, 'ancho (cm)', self::MAX_DIMENSION_CM, self::DIMENSION_PRECISION),
            self::validated($heightCm, 'alto (cm)', self::MAX_DIMENSION_CM, self::DIMENSION_PRECISION),
        );
    }

    /**
     * Ficha vacía: el producto todavía no tiene medidas cargadas.
     */
    public static function empty(): self
    {
        return new self(null, null, null, null);
    }

    /**
     * Reconstituye desde su forma primitiva (columnas o JSON). Acepta números o strings numéricos
     * (las columnas decimal llegan como string). TOLERANTE: un valor ausente, no numérico o fuera de
     * rango (dato histórico sucio) se toma como ausente en lugar de romper la carga del agregado; la
     * validación estricta de la entrada la hace {@see self::of()}.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::tolerant($data['weight_kg'] ?? null, self::MAX_WEIGHT_KG, self::WEIGHT_PRECISION),
            self::tolerant($data['length_cm'] ?? null, self::MAX_DIMENSION_CM, self::DIMENSION_PRECISION),
            self::tolerant($data['width_cm'] ?? null, self::MAX_DIMENSION_CM, self::DIMENSION_PRECISION),
            self::tolerant($data['height_cm'] ?? null, self::MAX_DIMENSION_CM, self::DIMENSION_PRECISION),
        );
    }

    public function isEmpty(): bool
    {
        return $this->weightKg === null
            && $this->lengthCm === null
            && $this->widthCm === null
            && $this->heightCm === null;
    }

    /**
     * True si están las tres dimensiones (condición para calcular el volumen).
     */
    public function hasAllDimensions(): bool
    {
        return $this->lengthCm !== null && $this->widthCm !== null && $this->heightCm !== null;
    }

    /**
     * True si la ficha está completa: peso y las tres dimensiones.
     */
    public function isComplete(): bool
    {
        return $this->weightKg !== null && $this->hasAllDimensions();
    }

    /**
     * Volumen en m³ (L × A × H / 1e6), o null si falta alguna dimensión.
     */
    public function volumeM3(): ?float
    {
        if ($this->lengthCm === null || $this->widthCm === null || $this->heightCm === null) {
            return null;
        }

        return round($this->lengthCm * $this->widthCm * $this->heightCm / 1_000_000, self::VOLUME_PRECISION);
    }

    public function equals(self $other): bool
    {
        return $this->weightKg === $other->weightKg
            && $this->lengthCm === $other->lengthCm
            && $this->widthCm === $other->widthCm
            && $this->heightCm === $other->heightCm;
    }

    /**
     * Forma primitiva (incluye el volumen derivado, de solo lectura).
     *
     * @return array{weight_kg: ?float, length_cm: ?float, width_cm: ?float, height_cm: ?float, volume_m3: ?float}
     */
    public function toArray(): array
    {
        return [
            'weight_kg' => $this->weightKg,
            'length_cm' => $this->lengthCm,
            'width_cm' => $this->widthCm,
            'height_cm' => $this->heightCm,
            'volume_m3' => $this->volumeM3(),
        ];
    }

    private static function validated(?float $value, string $label, float $max, int $precision): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_finite($value)) {
            throw CatalogException::because("El {$label} debe ser un número finito.");
        }
        if ($value <= 0.0) {
            throw CatalogException::because("El {$label} debe ser mayor a 0.");
        }
        if ($value > $max) {
            throw CatalogException::because("El {$label} no puede superar {$max}.");
        }

        $rounded = round($value, $precision);

        // Un valor positivo muy chico puede redondear a 0: se rechaza en vez de guardar un 0 inválido.
        if ($rounded <= 0.0) {
            throw CatalogException::because("El {$label} es demasiado chico para la precisión admitida.");
        }

        return $rounded;
    }

    private static function tolerant(mixed $value, float $max, int $precision): ?float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } elseif (is_string($value) && is_numeric($value)) {
            $number = (float) $value;
        } else {
            return null;
        }

        if (!is_finite($number) || $number <= 0.0 || $number > $max) {
            return null;
        }

        $rounded = round($number, $precision);

        return $rounded > 0.0 ? $rounded : null;
    }
}
