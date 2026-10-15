<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\RegisterDriver;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\LicenseNumber;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el chofer, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RegisterDriver>
 */
final readonly class RegisterDriverHandler implements CommandHandler
{
    public function __construct(
        private DriverRepository $drivers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterDriver);

        $driver = Driver::register(
            $this->drivers->nextIdentity(),
            $command->name,
            LicenseNumber::of($command->licenseNumber),
            DocumentExpiry::of($command->licenseDocumentType, new DateTimeImmutable($command->licenseExpiry)),
            array_values($command->enabledVehicleIds),
            $command->active,
        );

        $this->drivers->save($driver);

        $this->recordEvents->record($driver->releaseEvents(), 'fleet.driver');
    }
}
