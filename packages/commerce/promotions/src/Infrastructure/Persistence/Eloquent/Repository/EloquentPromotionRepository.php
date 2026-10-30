<?php

declare(strict_types=1);

namespace Commerce\Promotions\Infrastructure\Persistence\Eloquent\Repository;

use Commerce\Promotions\Domain\Contract\PromotionRepository;
use Commerce\Promotions\Domain\Model\Promotion;
use Commerce\Promotions\Domain\ValueObject\PromotionId;
use Commerce\Promotions\Domain\ValueObject\PromotionStatus;
use Commerce\Promotions\Infrastructure\Persistence\Eloquent\Mapper\PromotionMapper;
use Commerce\Promotions\Infrastructure\Persistence\Eloquent\Model\PromotionRecord;

/**
 * Implementación Eloquent del repositorio de promociones. `save` es un upsert por id (las condiciones
 * viajan en la misma fila como JSONB).
 */
final readonly class EloquentPromotionRepository implements PromotionRepository
{
    public function __construct(
        private PromotionMapper $mapper = new PromotionMapper,
    ) {}

    public function nextIdentity(): PromotionId
    {
        return PromotionId::generate();
    }

    public function save(Promotion $promotion): void
    {
        $row = $this->mapper->toRow($promotion);
        PromotionRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(PromotionId $id): ?Promotion
    {
        $record = PromotionRecord::query()->find($id->toString());

        return $record instanceof PromotionRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByCode(string $code): ?Promotion
    {
        $record = PromotionRecord::query()->where('code', $code)->first();

        return $record instanceof PromotionRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(PromotionRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (PromotionRecord $record): Promotion => $this->mapper->toDomain($record))
            ->all());
    }

    public function listActive(): array
    {
        return array_values(PromotionRecord::query()
            ->where('status', PromotionStatus::Active->value)
            ->orderBy('name')
            ->get()
            ->map(fn (PromotionRecord $record): Promotion => $this->mapper->toDomain($record))
            ->all());
    }
}
