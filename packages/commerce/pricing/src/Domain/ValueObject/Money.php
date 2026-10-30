<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\ValueObject;

use Commerce\Pricing\Domain\Exception\PricingException;

/**
 * Monto monetario: cantidad entera en centavos + moneda. Réplica del patrón `Money` por contexto con la
 * Exception propia de pricing (acoplamiento cero entre contextos).
 *
 * Se guarda en centavos (entero) para que las sumas, comparaciones y el cálculo de impuestos sean
 * exactos, sin los errores de redondeo del punto flotante. Moneda por defecto BOB (Dismac opera en
 * Bolivia).
 */
final readonly class Money
{
    public const DEFAULT_CURRENCY = 'BOB';

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
            throw PricingException::because("El monto no puede ser negativo: {$cents}.");
        }
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw PricingException::because('La moneda no puede estar vacía.');
        }

        return new self($cents, $currency);
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
            throw PricingException::because('La resta de montos no puede dar negativo.');
        }

        return new self($result, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw PricingException::because("El factor no puede ser negativo: {$factor}.");
        }

        return new self($this->cents * $factor, $this->currency);
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
            throw PricingException::because(
                "No se pueden operar montos de distinta moneda: {$this->currency} vs {$other->currency}.",
            );
        }
    }
}
