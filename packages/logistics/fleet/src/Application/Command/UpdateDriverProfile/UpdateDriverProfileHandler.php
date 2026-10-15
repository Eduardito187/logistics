<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\UpdateDriverProfile;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\BirthDate;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\DriverProfile;
use Logistics\Fleet\Domain\ValueObject\LicenseCategory;
use Logistics\Fleet\Domain\ValueObject\NationalId;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;
use Logistics\Fleet\Domain\ValueObject\PhoneNumber;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el chofer, le actualiza los datos personales y persiste. Falla si el chofer no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<UpdateDriverProfile>
 */
final readonly class UpdateDriverProfileHandler implements CommandHandler
{
    public function __construct(
        private DriverRepository $drivers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateDriverProfile);

        $driver = $this->drivers->findById(DriverId::fromString($command->driverId));
        if ($driver === null) {
            throw FleetException::because("No existe el chofer {$command->driverId}.");
        }

        $references = array_map(
            static fn (array $reference): PersonalReference => PersonalReference::of(
                $reference['name'],
                $reference['relationship'] ?? null,
                $reference['phone'] ?? null,
                $reference['note'] ?? null,
            ),
            $command->references,
        );

        $driver->updateProfile(new DriverProfile(
            $command->photoPath,
            $command->birthDate !== null ? BirthDate::of(new DateTimeImmutable($command->birthDate)) : null,
            $command->nationalId !== null ? NationalId::of($command->nationalId) : null,
            $command->phonePrimary !== null ? PhoneNumber::of($command->phonePrimary) : null,
            $command->phoneSecondary !== null ? PhoneNumber::of($command->phoneSecondary) : null,
            $command->address,
            $command->licenseCategory !== null ? LicenseCategory::from($command->licenseCategory) : null,
            $command->emergencyContactName,
            $command->emergencyContactPhone !== null ? PhoneNumber::of($command->emergencyContactPhone) : null,
            array_values($references),
            $command->licenseCity,
        ));

        $this->drivers->save($driver);

        $this->recordEvents->record($driver->releaseEvents(), 'fleet.driver');
    }
}
