<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Importe monetario con moneda. En este contexto se usa como PRECIO UNITARIO opcional de una línea,
 * para que las notificaciones al cliente (mail con detalle de productos) puedan mostrar el precio.
 *
 * El monto se guarda como decimal (grados, no centavos) con la moneda explícita; por defecto BOB
 * (bolivianos), que es la moneda del piloto. Es un dato de presentación: el contexto no cobra ni valida
 * contra SAP. Si la fuente no manda precio, la línea queda sin precio (null).
 */
final readonly class Money
{
    private const DEFAULT_CURRENCY = 'BOB';

    private function __construct(
        public float $amount,
        public string $currency,
    ) {}

    public static function of(float $amount, string $currency = self::DEFAULT_CURRENCY): self
    {
        if ($amount < 0.0) {
            throw ShipmentsException::because("el importe no puede ser negativo: {$amount}");
        }
        $normalizedCurrency = strtoupper(trim($currency));
        if ($normalizedCurrency === '') {
            $normalizedCurrency = self::DEFAULT_CURRENCY;
        }
        if (mb_strlen($normalizedCurrency) !== 3) {
            throw ShipmentsException::because("código de moneda inválido (ISO 4217): {$currency}");
        }

        return new self(round($amount, 2), $normalizedCurrency);
    }

    /**
     * Named constructor tolerante para la ingesta: devuelve null si no hay monto, en vez de lanzar.
     */
    public static function fromNullable(?float $amount, string $currency = self::DEFAULT_CURRENCY): ?self
    {
        if ($amount === null) {
            return null;
        }

        return self::of($amount, $currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
