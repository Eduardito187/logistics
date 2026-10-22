<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\MessageTemplate;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageTemplateId;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Traduce entre el agregado MessageTemplate y su fila Eloquent.
 */
final class MessageTemplateMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(MessageTemplate $template): array
    {
        return [
            'id' => $template->id()->toString(),
            'message_type' => $template->messageType()->value,
            'channel' => $template->channel()->value,
            'locale' => $template->locale(),
            'subject' => $template->subject(),
            'body' => $template->body(),
        ];
    }

    public function toDomain(MessageTemplateRecord $record): MessageTemplate
    {
        return MessageTemplate::reconstitute(
            MessageTemplateId::fromString($record->id),
            MessageType::from($record->message_type),
            Channel::fromStored($record->channel)
                ?? throw NotificationsException::because(
                    "la plantilla {$record->id} usa un canal que ya no existe: {$record->channel}",
                ),
            $record->locale,
            $record->subject,
            $record->body,
        );
    }
}
