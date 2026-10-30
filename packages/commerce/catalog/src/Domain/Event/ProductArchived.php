<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se archivó un producto: deja de ser comprable, se conserva su histórico.
 */
final readonly class ProductArchived extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'catalog.product_archived';
    }

    public function payload(): array
    {
        return [];
    }
}
