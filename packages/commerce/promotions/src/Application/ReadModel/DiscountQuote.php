<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\ReadModel;

/**
 * Resultado de evaluar las promociones contra un subtotal/canal/cupón: qué promociones aplicaron, el
 * descuento total (no negativo, topado al subtotal) y si corresponde envío gratis. Primitivos puros.
 */
final readonly class DiscountQuote
{
    /**
     * @param list<array{promotion_id: string, name: string, discount_cents: int, free_shipping: bool}> $applied
     */
    private function __construct(
        public array $applied,
        public int $totalDiscountCents,
        public bool $freeShipping,
    ) {}

    /**
     * @param list<array{promotion_id: string, name: string, discount_cents: int, free_shipping: bool}> $applied
     */
    public static function of(array $applied, int $totalDiscountCents, bool $freeShipping): self
    {
        return new self($applied, $totalDiscountCents, $freeShipping);
    }

    /**
     * @return array{applied: list<array{promotion_id: string, name: string, discount_cents: int, free_shipping: bool}>, total_discount_cents: int, free_shipping: bool}
     */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'total_discount_cents' => $this->totalDiscountCents,
            'free_shipping' => $this->freeShipping,
        ];
    }
}
