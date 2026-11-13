<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Compensation\Domain\Exception\CompensationException;

/**
 * Monto monetario: cantidad entera en centavos + moneda. Sin floats (sumas y topes exactos).
 *
 * El dominio de compensación solo opera en BOB (regla 2.1.1: línea en otra moneda ⇒ no hay automático);
 * se permite construir otra moneda para mensajes de error claros, pero las operaciones exigen misma moneda.
 */
final readonly class Money
{
    public const string DEFAULT_CURRENCY = 'BOB';

    private function __construct(
        public int $cents,
        public string $currency,
    ) {}

    public static function of(int $cents, string $currency = self::DEFAULT_CURRENCY): self
    {
        if ($cents < 0) {
            throw CompensationException::because("El monto no puede ser negativo: {$cents}.");
        }
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw CompensationException::because('La moneda no puede estar vacía.');
        }

        return new self($cents, $currency);
    }

    public static function zero(string $currency = self::DEFAULT_CURRENCY): self
    {
        return self::of(0, $currency);
    }

    /**
     * Devuelve el menor de dos montos (misma moneda).
     */
    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->cents <= $other->cents ? $this : $other;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    /**
     * Resta otro monto; nunca da negativo (se topa en cero): el acumulado disponible nunca es negativo.
     */
    public function subtractFloored(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(max(0, $this->cents - $other->cents), $this->currency);
    }

    /**
     * Redondea SIEMPRE hacia abajo al múltiplo de $step (en centavos). step <= 0 devuelve el mismo monto.
     */
    public function floorTo(int $step): self
    {
        if ($step <= 1) {
            return $this;
        }

        return new self(intdiv($this->cents, $step) * $step, $this->currency);
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents < $other->cents;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    public function isBob(): bool
    {
        return $this->currency === self::DEFAULT_CURRENCY;
    }

    /**
     * Representación legible para mensajes/log de dominio (no localizada): "Bs 157,00".
     * La presentación puede formatear distinto; esto es un apoyo, nunca null/vacío.
     */
    public function formatted(): string
    {
        return 'Bs '.number_format($this->cents / 100, 2, ',', '.');
    }

    /**
     * @return array{cents: int, currency: string}
     */
    public function toArray(): array
    {
        return ['cents' => $this->cents, 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CompensationException::because(
                "No se pueden operar montos de distinta moneda: {$this->currency} vs {$other->currency}.",
            );
        }
    }
}
