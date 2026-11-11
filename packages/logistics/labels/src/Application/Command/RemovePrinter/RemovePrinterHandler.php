<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\RemovePrinter;

use Logistics\Labels\Domain\Contract\PrinterRepository;
use Logistics\Labels\Domain\ValueObject\PrinterId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Baja física de una impresora del registro. Idempotente: borrar una inexistente no falla.
 *
 * @implements CommandHandler<RemovePrinter>
 */
final readonly class RemovePrinterHandler implements CommandHandler
{
    public function __construct(
        private PrinterRepository $printers,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RemovePrinter);

        $this->printers->remove(PrinterId::fromString($command->printerId));
    }
}
