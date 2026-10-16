<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se dio de alta un seller nuevo (arranca en estado Onboarding).
 */
final readonly class SellerRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $legalName,
        public string $taxId,
        public string $status,
        public string $contactName,
        public string $email,
        public ?string $phone,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'sellers.seller_registered';
    }

    public function payload(): array
    {
        return [
            'legal_name' => $this->legalName,
            'tax_id' => $this->taxId,
            'status' => $this->status,
            'contact_name' => $this->contactName,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }
}
