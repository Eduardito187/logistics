<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\RegisterDriver;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\LicenseNumber;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el chofer, lo persiste y libera sus eventos.
 *
 * TODO(outbox): publicar los eventos liberados cuando se integre el package outbox.
 *
 * @implements CommandHandler<RegisterDriver>
 */
final readonly class RegisterDriverHandler implements CommandHandler
{
    public function __construct(
        private DriverRepository $drivers,
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
        $driver->releaseEvents();
    }
}
