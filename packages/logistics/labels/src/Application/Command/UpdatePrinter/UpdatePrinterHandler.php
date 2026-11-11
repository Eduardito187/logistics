<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\UpdatePrinter;

use Logistics\Labels\Domain\Contract\PrinterRepository;
use Logistics\Labels\Domain\Exception\InvalidPrinterException;
use Logistics\Labels\Domain\ValueObject\PrinterEndpoint;
use Logistics\Labels\Domain\ValueObject\PrinterId;
use Logistics\Labels\Domain\ValueObject\PrinterLanguage;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Edición de una impresora existente (datos + activo/inactivo).
 *
 * @implements CommandHandler<UpdatePrinter>
 */
final readonly class UpdatePrinterHandler implements CommandHandler
{
    public function __construct(
        private PrinterRepository $printers,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpdatePrinter);

        $printer = $this->printers->find(PrinterId::fromString($command->printerId))
            ?? throw InvalidPrinterException::notFound($command->printerId);

        $printer->update(
            name: $command->name,
            model: $command->model,
            endpoint: PrinterEndpoint::of($command->host, $command->port),
            language: PrinterLanguage::from($command->language),
            nodeId: $command->nodeId,
            location: $command->location,
            active: $command->active,
        );

        $this->printers->save($printer);
    }
}
