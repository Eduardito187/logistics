<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

use Logistics\FleetOps\Domain\Exception\FleetOpsException;

/**
 * Importe monetario en centavos (entero, para exactitud) + moneda. Por defecto BOB (bolivianos), que es
 * la moneda operativa de la flota en Bolivia. Propio del contexto (aislamiento DDD): no se importa el
 * Money de carriers.
 *
 * Se guarda como `*_cents` (int) + `currency` (string) en las tablas, nunca como float.
 */
final readonly class Money
{
    public const DEFAULT_CURRENCY = 'BOB';

    private function __construct(
        public int $cents,
        public string $currency,
    ) {}

    public static function of(int $cents, string $currency = self::DEFAULT_CURRENCY): self
    {
        if ($cents < 0) {
            throw FleetOpsException::because("El importe no puede ser negativo: {$cents}.");
        }
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw FleetOpsException::because('La moneda no puede estar vacía.');
        }

        return new self($cents, $currency);
    }

    public static function zero(string $currency = self::DEFAULT_CURRENCY): self
    {
        return new self(0, strtoupper(trim($currency)) ?: self::DEFAULT_CURRENCY);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($other->cents > $this->cents) {
            throw FleetOpsException::because('La resta de importes no puede dar negativo.');
        }

        return new self($this->cents - $other->cents, $this->currency);
    }

    /**
     * Multiplica por una cantidad (p. ej. litros × precio, redondeando a centavos).
     */
    public function multiply(float $factor): self
    {
        if ($factor < 0) {
            throw FleetOpsException::because('El factor no puede ser negativo.');
        }

        return new self((int) round($this->cents * $factor), $this->currency);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents > $other->cents;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
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
            throw FleetOpsException::because(
                "No se pueden operar importes de distinta moneda ({$this->currency} vs {$other->currency}).",
            );
        }
    }
}
