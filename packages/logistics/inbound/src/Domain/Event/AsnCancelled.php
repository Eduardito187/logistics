<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se canceló el ASN: no se va a recibir. Estado terminal. Libera el cupo de andén si tenía cita.
 */
final readonly class AsnCancelled extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public ?string $reason,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'inbound.asn_cancelled';
    }

    public function payload(): array
    {
        return [
            'reason' => $this->reason,
        ];
    }
}
