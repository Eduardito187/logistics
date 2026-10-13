<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

use Logistics\Carriers\Domain\Exception\CarriersException;

/**
 * Monto monetario: cantidad entera en centavos + moneda.
 *
 * Se guarda en centavos (entero) para que las sumas y comparaciones de tarifas sean exactas, sin los
 * errores de redondeo del punto flotante. Las operaciones son seguras: solo se combinan montos de la
 * misma moneda, y el resultado no puede ser negativo.
 */
final readonly class Money
{
    private function __construct(
        public int $cents,
        public string $currency,
    ) {}

    /**
     * @param int    $cents    cantidad en centavos (>= 0)
     * @param string $currency código de moneda, p. ej. "BOB" o "USD"
     */
    public static function of(int $cents, string $currency): self
    {
        if ($cents < 0) {
            throw CarriersException::because("El monto no puede ser negativo: {$cents}.");
        }
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw CarriersException::because('La moneda no puede estar vacía.');
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
            throw CarriersException::because('La resta de montos no puede dar negativo.');
        }

        return new self($result, $this->currency);
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents < $other->cents;
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
            throw CarriersException::because(
                "No se pueden operar montos de distinta moneda: {$this->currency} vs {$other->currency}.",
            );
        }
    }
}
