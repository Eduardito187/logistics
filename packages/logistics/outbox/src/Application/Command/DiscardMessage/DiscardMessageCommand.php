<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Command\DiscardMessage;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de descartar manualmente un mensaje del outbox (mandarlo a dead-letter) con un motivo.
 */
final readonly class DiscardMessageCommand implements Command
{
    public function __construct(
        public string $messageId,
        public string $reason,
    ) {}
}
