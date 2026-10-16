<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se cerró el ASN: recepción conciliada y unidades etiquetadas. Estado terminal.
 */
final readonly class AsnClosed extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'inbound.asn_closed';
    }

    public function payload(): array
    {
        return [];
    }
}
