<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Promise\Domain\ValueObject\PromiseStatus;

/**
 * Implementación Eloquent del repositorio de promesas.
 *
 * save() es un upsert por id: reescribe la fila con el estado actual del agregado (la promesa no tiene
 * entidades hijas, así que basta una tabla).
 */
final class EloquentPromiseRepository implements PromiseRepository
{
    public function __construct(
        private readonly PromiseMapper $mapper,
    ) {}

    public function nextIdentity(): PromiseId
    {
        return PromiseId::generate();
    }

    public function save(DeliveryPromise $promise): void
    {
        PromiseRecord::query()->updateOrCreate(
            ['id' => $promise->id()->toString()],
            $this->mapper->toRow($promise),
        );
    }

    public function findById(PromiseId $id): ?DeliveryPromise
    {
        $record = PromiseRecord::query()->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function reservedExpiredAt(DateTimeImmutable $now): array
    {
        $records = PromiseRecord::query()
            ->where('status', PromiseStatus::Reserved->value)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', $now->format(DateTimeImmutable::ATOM))
            ->get();

        return $records
            ->map(static fn (PromiseRecord $record): PromiseId => PromiseId::fromString($record->id))
            ->values()
            ->all();
    }
}
