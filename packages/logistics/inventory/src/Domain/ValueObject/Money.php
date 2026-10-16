<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\ValueObject;

use Logistics\Inventory\Domain\Exception\InventoryException;

/**
 * Monto monetario: cantidad entera en centavos + moneda. Réplica del `carriers/Money` con la Exception
 * propia del contexto (patrón "VO Money por contexto" de la skill: acoplamiento cero entre contextos).
 *
 * Representa el costo/valor unitario de un lote. Se guarda en centavos (entero) para que las sumas y
 * comparaciones sean exactas, sin errores de redondeo del punto flotante. Moneda por defecto BOB.
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
            throw InventoryException::because("El monto no puede ser negativo: {$cents}.");
        }
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw InventoryException::because('La moneda no puede estar vacía.');
        }

        return new self($cents, $currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw InventoryException::because("El factor no puede ser negativo: {$factor}.");
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
}
