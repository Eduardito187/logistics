<?php

declare(strict_types=1);

namespace Commerce\Accounts\Infrastructure\Persistence\Eloquent\Repository;

use Commerce\Accounts\Domain\Contract\AccountRepository;
use Commerce\Accounts\Domain\Model\Account;
use Commerce\Accounts\Domain\ValueObject\AccountId;
use Commerce\Accounts\Infrastructure\Persistence\Eloquent\Mapper\AccountMapper;
use Commerce\Accounts\Infrastructure\Persistence\Eloquent\Model\AccountRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del repositorio de cuentas. `save` es un upsert por id (las direcciones viajan
 * en la misma fila como JSONB).
 */
final readonly class EloquentAccountRepository implements AccountRepository
{
    public function __construct(
        private AccountMapper $mapper = new AccountMapper,
    ) {}

    public function nextIdentity(): AccountId
    {
        return AccountId::generate();
    }

    public function save(Account $account): void
    {
        $row = $this->mapper->toRow($account);
        AccountRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(AccountId $id): ?Account
    {
        $record = AccountRecord::query()->find($id->toString());

        return $record instanceof AccountRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByUserId(Uuid $userId): ?Account
    {
        $record = AccountRecord::query()->where('user_id', $userId->value)->first();

        return $record instanceof AccountRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(AccountRecord::query()
            ->orderBy('display_name')
            ->get()
            ->map(fn (AccountRecord $record): Account => $this->mapper->toDomain($record))
            ->all());
    }
}
