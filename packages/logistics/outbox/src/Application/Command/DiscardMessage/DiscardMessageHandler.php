<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Command\DiscardMessage;

use Logistics\Outbox\Domain\Contract\OutboxReader;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\Exception\OutboxException;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Descarta manualmente un mensaje despachable (lo manda a dead-letter) con un motivo. Para sacar de
 * la cola un evento que nunca se va a poder despachar, sin esperar a agotar los reintentos.
 *
 * @implements CommandHandler<DiscardMessageCommand>
 */
final readonly class DiscardMessageHandler implements CommandHandler
{
    public function __construct(
        private OutboxReader $reader,
        private OutboxStore $store,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DiscardMessageCommand);

        $message = $this->reader->findById(OutboxMessageId::fromString($command->messageId));

        if ($message === null) {
            throw OutboxException::because("no existe el mensaje de outbox {$command->messageId}");
        }

        $message->discard($command->reason);

        $this->store->save($message);
    }
}
