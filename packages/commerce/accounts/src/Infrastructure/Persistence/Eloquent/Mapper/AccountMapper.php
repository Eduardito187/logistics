<?php

declare(strict_types=1);

namespace Commerce\Accounts\Infrastructure\Persistence\Eloquent\Mapper;

use Commerce\Accounts\Domain\Model\Account;
use Commerce\Accounts\Domain\ValueObject\AccountId;
use Commerce\Accounts\Domain\ValueObject\AccountStatus;
use Commerce\Accounts\Domain\ValueObject\AccountType;
use Commerce\Accounts\Domain\ValueObject\Address;
use Commerce\Accounts\Infrastructure\Persistence\Eloquent\Model\AccountRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Traduce entre el agregado Account (dominio) y su fila Eloquent. Único punto que conoce ambos lados.
 */
final class AccountMapper
{
    public function toDomain(AccountRecord $record): Account
    {
        return Account::reconstitute(
            AccountId::fromString($record->id),
            AccountType::from($record->type),
            Uuid::fromString($record->user_id),
            $record->display_name,
            $record->tax_id,
            AccountStatus::from($record->status),
            array_values(array_map(
                static fn (array $address): Address => Address::fromArray($address),
                $record->addresses,
            )),
        );
    }

    /**
     * @return array{id: string, type: string, user_id: string, display_name: string, tax_id: ?string, status: string, addresses: list<array{label: string, recipient: string, line1: string, line2: ?string, city: string, state: ?string, phone: ?string, is_default: bool}>}
     */
    public function toRow(Account $account): array
    {
        return [
            'id' => $account->id()->toString(),
            'type' => $account->type()->value,
            'user_id' => $account->userId()->value,
            'display_name' => $account->displayName(),
            'tax_id' => $account->taxId(),
            'status' => $account->status()->value,
            'addresses' => array_values(array_map(
                static fn (Address $address): array => $address->toArray(),
                $account->addresses(),
            )),
        ];
    }
}
