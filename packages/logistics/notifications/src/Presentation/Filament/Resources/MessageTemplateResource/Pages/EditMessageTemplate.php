<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Notifications\Application\Command\UpsertMessageTemplate\UpsertMessageTemplate;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTemplateRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de una plantilla de mensaje: solo revisa asunto y cuerpo (la identidad tipo/canal/locale es
 * la clave única y no se edita). Despacha UpsertMessageTemplate al bus CQRS; no escribe el Record.
 */
final class EditMessageTemplate extends EditRecord
{
    protected static string $resource = MessageTemplateResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (!$record instanceof MessageTemplateRecord) {
            return $record;
        }

        // La identidad viene del record (los campos están deshabilitados en el formulario de edición).
        app(CommandBus::class)->dispatch(new UpsertMessageTemplate(
            messageType: (string) $record->message_type,
            channel: (string) $record->channel,
            locale: (string) $record->locale,
            subject: ($data['subject'] ?? '') !== '' ? (string) $data['subject'] : null,
            body: (string) $data['body'],
        ));

        return $record->refresh();
    }
}
