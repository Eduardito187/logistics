<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

use Logistics\Fleet\Domain\Model\FleetDocument;

/**
 * Proyección de lectura de un documento de flota para listados y API. Solo primitivos.
 */
final readonly class DocumentView
{
    private function __construct(
        public string $id,
        public string $ownerType,
        public string $ownerId,
        public string $documentType,
        public string $expiresOn,
        public ?string $issuedOn,
        public ?string $filePath,
        public ?string $note,
        public ?string $reference,
    ) {}

    public static function fromDomain(FleetDocument $document): self
    {
        return new self(
            $document->id()->toString(),
            $document->ownerType()->value,
            $document->ownerId(),
            $document->document()->type,
            $document->document()->expiresOn->format('Y-m-d'),
            $document->issuedOn()?->format('Y-m-d'),
            $document->filePath(),
            $document->note(),
            $document->reference(),
        );
    }

    /**
     * @return array{id: string, owner_type: string, owner_id: string, document_type: string, expires_on: string, issued_on: string|null, file_path: string|null, note: string|null, reference: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
            'document_type' => $this->documentType,
            'expires_on' => $this->expiresOn,
            'issued_on' => $this->issuedOn,
            'file_path' => $this->filePath,
            'note' => $this->note,
            'reference' => $this->reference,
        ];
    }
}
