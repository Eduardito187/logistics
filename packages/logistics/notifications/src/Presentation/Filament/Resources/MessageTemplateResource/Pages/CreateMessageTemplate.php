<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Notifications\Application\Command\UpsertMessageTemplate\UpsertMessageTemplate;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTemplateRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de una plantilla de mensaje.
 *
 * No persiste con Eloquent: despacha el comando UpsertMessageTemplate al bus CQRS (el dominio genera el
 * id, valida la plantilla y graba el hecho de configuración en el outbox). Como el comando no devuelve
 * el record, se recupera por su clave única (tipo + canal + locale) para la redirección del admin.
 */
final class CreateMessageTemplate extends CreateRecord
{
    protected static string $resource = MessageTemplateResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $messageType = (string) $data['message_type'];
        $channel = (string) $data['channel'];
        $locale = strtolower(trim((string) ($data['locale'] ?? 'es')));

        app(CommandBus::class)->dispatch(new UpsertMessageTemplate(
            messageType: $messageType,
            channel: $channel,
            locale: $locale,
            subject: ($data['subject'] ?? '') !== '' ? (string) $data['subject'] : null,
            body: (string) $data['body'],
        ));

        // La tupla (tipo, canal, locale) es única: identifica la plantilla recién definida.
        return MessageTemplateRecord::query()
            ->where('message_type', $messageType)
            ->where('channel', $channel)
            ->where('locale', $locale)
            ->firstOrFail();
    }
}
