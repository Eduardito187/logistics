<?php

declare(strict_types=1);

namespace Logistics\Shared\Application\Command;

/**
 * Maneja exactamente un tipo de comando. Un comando → un handler (CQRS).
 *
 * @template T of Command
 */
interface CommandHandler
{
    /**
     * @param T $command
     */
    public function handle(Command $command): void;
}
