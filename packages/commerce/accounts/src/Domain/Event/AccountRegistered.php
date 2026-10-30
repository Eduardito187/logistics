<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró una cuenta comercial nueva (activa). Referencia al usuario de `access` por UUID; `access`
 * sigue siendo la autoridad de identidad/authN (ADR 018).
 */
final readonly class AccountRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $type,
        public string $userId,
        public string $displayName,
        public ?string $taxId,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'accounts.account_registered';
    }

    public function payload(): array
    {
        return [
            'type' => $this->type,
            'user_id' => $this->userId,
            'display_name' => $this->displayName,
            'tax_id' => $this->taxId,
        ];
    }
}
