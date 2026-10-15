<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Command\RetryMessage;

use Logistics\Outbox\Domain\Contract\OutboxReader;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\Exception\OutboxException;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reencola un mensaje del outbox: lo deja Pending y disponible de inmediato para que el relay lo
 * vuelva a despachar. Acción operativa (panel/API); el dominio rechaza reencolar uno ya despachado.
 *
 * @implements CommandHandler<RetryMessageCommand>
 */
final readonly class RetryMessageHandler implements CommandHandler
{
    public function __construct(
        private OutboxReader $reader,
        private OutboxStore $store,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RetryMessageCommand);

        $message = $this->reader->findById(OutboxMessageId::fromString($command->messageId));

        if ($message === null) {
            throw OutboxException::because("no existe el mensaje de outbox {$command->messageId}");
        }

        $message->requeue($this->clock->now());

        $this->store->save($message);
    }
}
