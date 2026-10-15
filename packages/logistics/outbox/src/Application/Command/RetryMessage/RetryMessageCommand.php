<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Command\RetryMessage;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de reencolar un mensaje del outbox (fallido o en dead-letter) para que el relay lo retome.
 */
final readonly class RetryMessageCommand implements Command
{
    public function __construct(public string $messageId) {}
}
