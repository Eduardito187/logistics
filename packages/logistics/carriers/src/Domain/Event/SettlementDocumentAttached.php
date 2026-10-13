<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se adjuntó un documento (factura, comprobante) a una liquidación de transportista.
 */
final readonly class SettlementDocumentAttached extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $documentType,
        public string $documentReference,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.settlement_document_attached';
    }

    public function payload(): array
    {
        return [
            'document_type' => $this->documentType,
            'document_reference' => $this->documentReference,
        ];
    }
}
