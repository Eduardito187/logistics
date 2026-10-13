<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Notifications\Application\Command\SetMessageTypeRule\SetMessageTypeRule;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTypeRuleRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de la regla de cascada de un tipo de mensaje.
 *
 * No escribe el Record: despacha SetMessageTypeRule al bus CQRS (reemplaza la cascada del tipo respetando
 * invariantes y el outbox). El tipo de mensaje es la clave y no se edita (campo deshabilitado); solo
 * cambian los canales y la condición de escalado.
 */
final class EditMessageTypeRule extends EditRecord
{
    protected static string $resource = MessageTypeRuleResource::class;

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
        if (!$record instanceof MessageTypeRuleRecord) {
            return $record;
        }

        /** @var list<string> $channels */
        $channels = array_values(array_map('strval', (array) ($data['channels'] ?? [])));

        // El tipo viene del record (campo deshabilitado en el formulario de edición).
        app(CommandBus::class)->dispatch(new SetMessageTypeRule(
            messageType: (string) $record->message_type,
            channels: $channels,
            escalateWhen: (string) $data['escalate_when'],
        ));

        return $record->refresh();
    }
}
