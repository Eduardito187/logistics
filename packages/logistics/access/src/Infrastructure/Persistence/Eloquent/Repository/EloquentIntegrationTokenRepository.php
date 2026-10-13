<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Access\Domain\Contract\IntegrationTokenRepository;
use Logistics\Access\Domain\Model\IntegrationToken;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Mapper\IntegrationTokenMapper;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\IntegrationTokenRecord;

/**
 * Implementación Eloquent del repositorio de tokens de integración. `save` es un upsert por id.
 */
final readonly class EloquentIntegrationTokenRepository implements IntegrationTokenRepository
{
    public function __construct(
        private IntegrationTokenMapper $mapper = new IntegrationTokenMapper,
    ) {}

    public function nextIdentity(): IntegrationTokenId
    {
        return IntegrationTokenId::generate();
    }

    public function save(IntegrationToken $token): void
    {
        $row = $this->mapper->toRow($token);
        IntegrationTokenRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(IntegrationTokenId $id): ?IntegrationToken
    {
        $record = IntegrationTokenRecord::query()->find($id->toString());

        return $record instanceof IntegrationTokenRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByPrefix(string $prefix): ?IntegrationToken
    {
        $record = IntegrationTokenRecord::query()->where('token_prefix', $prefix)->first();

        return $record instanceof IntegrationTokenRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(IntegrationTokenRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (IntegrationTokenRecord $record): IntegrationToken => $this->mapper->toDomain($record))
            ->all());
    }
}
