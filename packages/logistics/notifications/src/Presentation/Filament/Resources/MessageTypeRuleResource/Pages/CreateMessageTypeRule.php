<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Notifications\Application\Command\SetMessageTypeRule\SetMessageTypeRule;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTypeRuleRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de una regla de cascada para un tipo de mensaje (la PK es el propio tipo).
 *
 * No persiste con Eloquent: despacha el comando SetMessageTypeRule al bus CQRS (valida los canales y la
 * condición de escalado, respeta invariantes y graba el hecho de configuración en el outbox). Como el
 * comando no devuelve el record, se recupera por su clave (el tipo de mensaje) para la redirección.
 */
final class CreateMessageTypeRule extends CreateRecord
{
    protected static string $resource = MessageTypeRuleResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $messageType = (string) $data['message_type'];

        /** @var list<string> $channels */
        $channels = array_values(array_map('strval', (array) ($data['channels'] ?? [])));

        $actor = auth()->user()?->getAuthIdentifier();

        app(CommandBus::class)->dispatch(new SetMessageTypeRule(
            messageType: $messageType,
            channels: $channels,
            escalateWhen: (string) $data['escalate_when'],
            createdBy: $actor !== null ? (string) $actor : null,
        ));

        // El tipo de mensaje es la clave primaria de la regla: identifica la recién definida.
        return MessageTypeRuleRecord::query()->findOrFail($messageType);
    }
}
