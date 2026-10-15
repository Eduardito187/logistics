<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró un documento de flota (con su vencimiento y, opcionalmente, su archivo en S3).
 */
final readonly class DocumentRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $ownerType,
        public string $ownerId,
        public string $documentType,
        public string $expiresOn,
        public ?string $issuedOn,
        public ?string $filePath,
        public ?string $reference = null,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.document_registered';
    }

    public function payload(): array
    {
        return [
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
            'document_type' => $this->documentType,
            'expires_on' => $this->expiresOn,
            'issued_on' => $this->issuedOn,
            'file_path' => $this->filePath,
            'reference' => $this->reference,
        ];
    }
}
