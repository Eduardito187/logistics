<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\SetMessageTypeRule;

use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Configura (upsert) la política de cascada de un tipo de mensaje. Construye la CascadePolicy desde los
 * primitivos del comando (validándola en el VO) y la persiste vía el repositorio de reglas.
 *
 * Es configuración, no un hecho de un envío: no emite eventos de outbox.
 *
 * @implements CommandHandler<SetMessageTypeRule>
 */
final class SetMessageTypeRuleHandler implements CommandHandler
{
    public function __construct(
        private readonly MessageTypeRuleRepository $rules,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof SetMessageTypeRule) {
            throw NotificationsException::because('SetMessageTypeRuleHandler solo maneja SetMessageTypeRule');
        }

        $messageType = MessageType::from($command->messageType);
        $channels = array_map(
            static fn (string $channel): Channel => Channel::from($channel),
            array_values($command->channels),
        );
        $escalateWhen = EscalateCondition::from($command->escalateWhen);

        $this->rules->save($messageType, CascadePolicy::of($channels, $escalateWhen));
    }
}
