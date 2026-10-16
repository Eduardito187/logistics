<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\ValueObject;

use Logistics\Returns\Domain\Exception\ReturnsException;

/**
 * Línea de una devolución: el SKU devuelto, la cantidad y el motivo. Value object inmutable.
 *
 * El motivo (`reason`) es texto libre del operador/canal (p. ej. "damaged", "customer_regret",
 * "wrong_item"); no se modela como enum para no acoplar el dominio a un catálogo de motivos que cambia.
 */
final readonly class ReturnLine
{
    private function __construct(
        public string $sku,
        public int $quantity,
        public string $reason,
    ) {}

    public static function of(string $sku, int $quantity, string $reason): self
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw ReturnsException::because('El SKU de la línea de devolución no puede estar vacío.');
        }
        if ($quantity <= 0) {
            throw ReturnsException::because("La cantidad devuelta debe ser positiva: {$quantity}.");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ReturnsException::because('La línea de devolución debe indicar un motivo.');
        }

        return new self($sku, $quantity, $reason);
    }

    public function equals(self $other): bool
    {
        return $this->sku === $other->sku
            && $this->quantity === $other->quantity
            && $this->reason === $other->reason;
    }

    /**
     * @return array{sku: string, quantity: int, reason: string}
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'reason' => $this->reason,
        ];
    }
}
