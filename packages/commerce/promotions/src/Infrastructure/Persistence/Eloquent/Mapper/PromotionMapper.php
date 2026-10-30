<?php

declare(strict_types=1);

namespace Commerce\Promotions\Infrastructure\Persistence\Eloquent\Mapper;

use Commerce\Promotions\Domain\Model\Promotion;
use Commerce\Promotions\Domain\ValueObject\PromotionConditions;
use Commerce\Promotions\Domain\ValueObject\PromotionId;
use Commerce\Promotions\Domain\ValueObject\PromotionStatus;
use Commerce\Promotions\Domain\ValueObject\PromotionType;
use Commerce\Promotions\Infrastructure\Persistence\Eloquent\Model\PromotionRecord;

/**
 * Traduce entre el agregado Promotion (dominio) y su fila Eloquent. Único punto que conoce ambos lados.
 */
final class PromotionMapper
{
    public function toDomain(PromotionRecord $record): Promotion
    {
        return Promotion::reconstitute(
            PromotionId::fromString($record->id),
            $record->name,
            $record->code,
            PromotionType::from($record->type),
            $record->value,
            $record->currency,
            PromotionConditions::fromArray($record->conditions),
            PromotionStatus::from($record->status),
            $record->version,
        );
    }

    /**
     * @return array{id: string, name: string, code: ?string, type: string, value: int, currency: string, conditions: array{min_subtotal_cents: int, channel: ?string, starts_at: ?string, ends_at: ?string}, status: string, version: int}
     */
    public function toRow(Promotion $promotion): array
    {
        return [
            'id' => $promotion->id()->toString(),
            'name' => $promotion->name(),
            'code' => $promotion->code(),
            'type' => $promotion->type()->value,
            'value' => $promotion->value(),
            'currency' => $promotion->currency(),
            'conditions' => $promotion->conditions()->toArray(),
            'status' => $promotion->status()->value,
            'version' => $promotion->version(),
        ];
    }
}
