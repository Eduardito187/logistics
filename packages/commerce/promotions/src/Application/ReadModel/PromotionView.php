<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\ReadModel;

use Commerce\Promotions\Domain\Model\Promotion;

/**
 * Proyección de lectura de una promoción para listados, API y panel. Primitivos: no expone dominio.
 */
final readonly class PromotionView
{
    /**
     * @param array{min_subtotal_cents: int, channel: ?string, starts_at: ?string, ends_at: ?string} $conditions
     */
    private function __construct(
        public string $id,
        public string $name,
        public ?string $code,
        public string $type,
        public int $value,
        public string $currency,
        public array $conditions,
        public string $status,
        public int $version,
        public bool $isCoupon,
        public bool $freeShipping,
    ) {}

    public static function fromDomain(Promotion $promotion): self
    {
        return new self(
            $promotion->id()->toString(),
            $promotion->name(),
            $promotion->code(),
            $promotion->type()->value,
            $promotion->value(),
            $promotion->currency(),
            $promotion->conditions()->toArray(),
            $promotion->status()->value,
            $promotion->version(),
            $promotion->isCoupon(),
            $promotion->isFreeShipping(),
        );
    }

    /**
     * @return array{id: string, name: string, code: ?string, type: string, value: int, currency: string, conditions: array{min_subtotal_cents: int, channel: ?string, starts_at: ?string, ends_at: ?string}, status: string, version: int, is_coupon: bool, free_shipping: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'currency' => $this->currency,
            'conditions' => $this->conditions,
            'status' => $this->status,
            'version' => $this->version,
            'is_coupon' => $this->isCoupon,
            'free_shipping' => $this->freeShipping,
        ];
    }
}
