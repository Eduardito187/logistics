<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Capacity\Domain\Exception\CapacityException;

/**
 * Cantidad de capacidad: un decimal no negativo con 3 decimales de precisión (coincide con la
 * columna decimal(12,3) de la base).
 *
 * Internamente se guarda como entero de milésimas para que add/subtract sean exactos y las
 * comparaciones no sufran los errores de redondeo del punto flotante. subtract nunca baja de 0:
 * liberar más de lo consumido deja el bucket en cero, no en negativo.
 */
final readonly class Quantity
{
    /** Precisión: 3 decimales → el valor se escala por 1000 y se guarda como entero de milésimas. */
    private const int SCALE = 1000;

    private function __construct(public int $thousandths) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function of(float $value): self
    {
        if ($value < 0.0) {
            throw CapacityException::because("la cantidad no puede ser negativa: {$value}");
        }

        // Redondeo al milésimo para no arrastrar ruido del flotante de entrada.
        return new self((int) round($value * self::SCALE));
    }

    /**
     * Reconstruye desde el entero de milésimas ya calculado (p. ej. al rehidratar desde la base).
     */
    public static function fromThousandths(int $thousandths): self
    {
        if ($thousandths < 0) {
            throw CapacityException::because("la cantidad no puede ser negativa: {$thousandths}");
        }

        return new self($thousandths);
    }

    public function add(self $other): self
    {
        return new self($this->thousandths + $other->thousandths);
    }

    /**
     * Resta otra cantidad; si el resultado sería negativo, devuelve cero (nunca baja de 0).
     */
    public function subtract(self $other): self
    {
        return new self(max(0, $this->thousandths - $other->thousandths));
    }

    public function isZero(): bool
    {
        return $this->thousandths === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->thousandths > $other->thousandths;
    }

    public function equals(self $other): bool
    {
        return $this->thousandths === $other->thousandths;
    }

    /**
     * Valor como decimal (3 decimales). Pensado para el modelo de lectura y la persistencia.
     */
    public function value(): float
    {
        return $this->thousandths / self::SCALE;
    }
}
