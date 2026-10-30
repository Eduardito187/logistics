<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\ReadModel;

use Commerce\Accounts\Domain\Model\Account;
use Commerce\Accounts\Domain\ValueObject\Address;

/**
 * Proyección de lectura de una cuenta para listados, API y storefront. Primitivos: no expone dominio.
 */
final readonly class AccountView
{
    /**
     * @param list<array{label: string, recipient: string, line1: string, line2: ?string, city: string, state: ?string, phone: ?string, is_default: bool}> $addresses
     */
    private function __construct(
        public string $id,
        public string $type,
        public string $userId,
        public string $displayName,
        public ?string $taxId,
        public string $status,
        public array $addresses,
    ) {}

    public static function fromDomain(Account $account): self
    {
        return new self(
            $account->id()->toString(),
            $account->type()->value,
            $account->userId()->value,
            $account->displayName(),
            $account->taxId(),
            $account->status()->value,
            array_map(static fn (Address $address): array => $address->toArray(), $account->addresses()),
        );
    }

    /**
     * @return array{id: string, type: string, user_id: string, display_name: string, tax_id: ?string, status: string, addresses: list<array{label: string, recipient: string, line1: string, line2: ?string, city: string, state: ?string, phone: ?string, is_default: bool}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'user_id' => $this->userId,
            'display_name' => $this->displayName,
            'tax_id' => $this->taxId,
            'status' => $this->status,
            'addresses' => $this->addresses,
        ];
    }
}
