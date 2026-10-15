<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\DocumentResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\RegisterDocument\RegisterDocument;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\FleetDocumentModel;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un documento de flota desde el admin.
 *
 * Despacha RegisterDocument por el CommandBus (el UUID lo genera el dominio) y relee el documento
 * recién creado (dueño + tipo + vencimiento, el más reciente) para que Filament muestre el resultado.
 */
final class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        // FileUpload puede entregar el path como string o como lista de un elemento: lo normalizamos.
        $filePath = $data['file_path'] ?? null;
        if (is_array($filePath)) {
            $filePath = $filePath[array_key_first($filePath)] ?? null;
        }
        $filePath = is_string($filePath) && $filePath !== '' ? $filePath : null;

        app(CommandBus::class)->dispatch(new RegisterDocument(
            ownerType: (string) $data['owner_type'],
            ownerId: (string) $data['owner_id'],
            documentType: (string) $data['document_type'],
            expiresOn: (string) $data['expires_on'],
            issuedOn: isset($data['issued_on']) && $data['issued_on'] !== '' ? (string) $data['issued_on'] : null,
            filePath: $filePath,
            note: isset($data['note']) && $data['note'] !== '' ? (string) $data['note'] : null,
        ));

        return FleetDocumentModel::query()
            ->where('owner_id', $data['owner_id'])
            ->where('document_type', $data['document_type'])
            ->where('expires_on', $data['expires_on'])
            ->latest()
            ->firstOrFail();
    }
}
