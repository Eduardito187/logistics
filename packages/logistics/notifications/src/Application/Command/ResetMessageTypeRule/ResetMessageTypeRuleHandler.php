<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\ResetMessageTypeRule;

use Logistics\Notifications\Application\Contract\CascadeRuleSettings;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Quita el override de cascada de un tipo de mensaje publicando una versión nueva de la configuración.
 *
 * @implements CommandHandler<ResetMessageTypeRule>
 */
final class ResetMessageTypeRuleHandler implements CommandHandler
{
    public function __construct(
        private readonly CascadeRuleSettings $rules,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ResetMessageTypeRule) {
            throw NotificationsException::because('ResetMessageTypeRuleHandler solo maneja ResetMessageTypeRule');
        }

        $this->rules->remove(MessageType::from($command->messageType), $command->createdBy);
    }
}
