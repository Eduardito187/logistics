<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\FleetDocument;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\FleetDocumentModel;

/**
 * Traduce entre el agregado FleetDocument (dominio) y su fila Eloquent.
 */
final class FleetDocumentMapper
{
    public function toDomain(FleetDocumentModel $model): FleetDocument
    {
        return FleetDocument::reconstitute(
            FleetDocumentId::fromString($model->id),
            DocumentOwnerType::from($model->owner_type),
            $model->owner_id,
            DocumentExpiry::of($model->document_type, new DateTimeImmutable($model->expires_on)),
            $model->issued_on !== null ? new DateTimeImmutable($model->issued_on) : null,
            $model->file_path,
            $model->note,
            $model->reference,
        );
    }

    /**
     * @return array{id: string, owner_type: string, owner_id: string, document_type: string, expires_on: string, issued_on: string|null, file_path: string|null, note: string|null, reference: string|null}
     */
    public function toRow(FleetDocument $document): array
    {
        return [
            'id' => $document->id()->toString(),
            'owner_type' => $document->ownerType()->value,
            'owner_id' => $document->ownerId(),
            'document_type' => $document->document()->type,
            'expires_on' => $document->document()->expiresOn->format('Y-m-d'),
            'issued_on' => $document->issuedOn()?->format('Y-m-d'),
            'file_path' => $document->filePath(),
            'note' => $document->note(),
            'reference' => $document->reference(),
        ];
    }
}
