<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\Contract;

use Commerce\Accounts\Domain\Model\Account;
use Commerce\Accounts\Domain\ValueObject\AccountId;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Puerto de persistencia de cuentas. La implementación vive en Infrastructure.
 */
interface AccountRepository
{
    public function nextIdentity(): AccountId;

    /**
     * Upsert de la cuenta por id (incluye sus direcciones).
     */
    public function save(Account $account): void;

    public function findById(AccountId $id): ?Account;

    /**
     * Busca la cuenta comercial asociada a un usuario de `access`, o null si no existe.
     */
    public function findByUserId(Uuid $userId): ?Account;

    /**
     * @return list<Account>
     */
    public function all(): array;
}
