<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\RegisterPrinter;

use Logistics\Labels\Domain\Contract\PrinterRepository;
use Logistics\Labels\Domain\Model\Printer;
use Logistics\Labels\Domain\ValueObject\PrinterDetails;
use Logistics\Labels\Domain\ValueObject\PrinterEndpoint;
use Logistics\Labels\Domain\ValueObject\PrinterId;
use Logistics\Labels\Domain\ValueObject\PrinterLanguage;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Alta de una impresora de red: valida en el dominio (nombre, host, puerto, lenguaje) y la persiste.
 *
 * @implements CommandHandler<RegisterPrinter>
 */
final readonly class RegisterPrinterHandler implements CommandHandler
{
    public function __construct(
        private PrinterRepository $printers,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterPrinter);

        $this->printers->save(Printer::register(
            PrinterId::fromString($command->printerId),
            new PrinterDetails(
                name: $command->name,
                model: $command->model,
                endpoint: PrinterEndpoint::of($command->host, $command->port),
                language: PrinterLanguage::from($command->language),
                nodeId: $command->nodeId,
                location: $command->location,
            ),
            $command->active,
        ));
    }
}
