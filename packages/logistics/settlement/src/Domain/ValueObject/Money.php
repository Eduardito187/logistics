<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\ValueObject;

use Logistics\Settlement\Domain\Exception\SettlementException;

/**
 * Monto monetario: cantidad entera en centavos + moneda. Réplica del `carriers/Money` con la Exception
 * propia del contexto (patrón "VO Money por contexto" de la skill: acoplamiento cero entre contextos).
 *
 * Se guarda en centavos (entero) para que las sumas, comisiones y comparaciones sean exactas, sin los
 * errores de redondeo del punto flotante. Moneda por defecto BOB (Dismac opera en Bolivia).
 */
final readonly class Money
{
    public const DEFAULT_CURRENCY = 'BOB';

    /** Base de los basis points: 10000 bp = 100 %. */
    private const BASIS_POINTS_DIVISOR = 10000;

    private function __construct(
        public int $cents,
        public string $currency,
    ) {}

    /**
     * @param int    $cents    cantidad en centavos (>= 0)
     * @param string $currency código de moneda, p. ej. "BOB" o "USD"
     */
    public static function of(int $cents, string $currency = self::DEFAULT_CURRENCY): self
    {
        if ($cents < 0) {
            throw SettlementException::because("El monto no puede ser negativo: {$cents}.");
        }
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw SettlementException::because('La moneda no puede estar vacía.');
        }

        return new self($cents, $currency);
    }

    /**
     * Monto cero en la moneda dada (útil para inicializar fees diferidos en 0).
     */
    public static function zero(string $currency = self::DEFAULT_CURRENCY): self
    {
        return self::of(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    /**
     * Resta otro monto. Falla si el resultado quedaría negativo (operación segura).
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        $result = $this->cents - $other->cents;
        if ($result < 0) {
            throw SettlementException::because('La resta de montos no puede dar negativo.');
        }

        return new self($result, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw SettlementException::because("El factor no puede ser negativo: {$factor}.");
        }

        return new self($this->cents * $factor, $this->currency);
    }

    /**
     * Aplica una tasa en basis points (1 bp = 0,01 %) y trunca al centavo (comisión). 1500 bp = 15 %.
     */
    public function percentageByBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw SettlementException::because("Los basis points no pueden ser negativos: {$basisPoints}.");
        }

        return new self(intdiv($this->cents * $basisPoints, self::BASIS_POINTS_DIVISOR), $this->currency);
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
            throw SettlementException::because(
                "No se pueden operar montos de distinta moneda: {$this->currency} vs {$other->currency}.",
            );
        }
    }
}
