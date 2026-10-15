<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\UpsertMessageTemplate;

use Logistics\Notifications\Domain\Contract\MessageTemplateRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\MessageTemplate;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Crea o actualiza una plantilla de mensaje (única por tipo, canal y locale). Si ya existe, revisa su
 * contenido; si no, la define. Persiste y libera el hecho de configuración para auditoría (outbox).
 *
 * @implements CommandHandler<UpsertMessageTemplate>
 */
final class UpsertMessageTemplateHandler implements CommandHandler
{
    public function __construct(
        private readonly MessageTemplateRepository $templates,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof UpsertMessageTemplate) {
            throw NotificationsException::because('UpsertMessageTemplateHandler solo maneja UpsertMessageTemplate');
        }

        $messageType = MessageType::from($command->messageType);
        $channel = Channel::from($command->channel);
        $locale = $command->locale;

        $existing = $this->templates->findFor($messageType, $channel, $locale);

        if ($existing === null) {
            $template = MessageTemplate::define(
                $this->templates->nextIdentity(),
                $messageType,
                $channel,
                $locale,
                $command->subject,
                $command->body,
            );
        } else {
            $existing->revise($command->subject, $command->body);
            $template = $existing;
        }

        $this->templates->save($template);

        $this->recordEvents->record($template->releaseEvents(), 'notifications.message_template');
    }
}
