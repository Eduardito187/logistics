<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Persistence\Eloquent;

use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\PromiseId;

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
}
