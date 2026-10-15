<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\UpdateHelperProfile;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\BirthDate;
use Logistics\Fleet\Domain\ValueObject\HelperId;
use Logistics\Fleet\Domain\ValueObject\HelperProfile;
use Logistics\Fleet\Domain\ValueObject\NationalId;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;
use Logistics\Fleet\Domain\ValueObject\PhoneNumber;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el ayudante, le actualiza los datos personales y persiste. Falla si el ayudante no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<UpdateHelperProfile>
 */
final readonly class UpdateHelperProfileHandler implements CommandHandler
{
    public function __construct(
        private HelperRepository $helpers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateHelperProfile);

        $helper = $this->helpers->findById(HelperId::fromString($command->helperId));
        if ($helper === null) {
            throw FleetException::because("No existe el ayudante {$command->helperId}.");
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

        $helper->updateProfile(new HelperProfile(
            $command->photoPath,
            $command->birthDate !== null ? BirthDate::of(new DateTimeImmutable($command->birthDate)) : null,
            $command->nationalId !== null ? NationalId::of($command->nationalId) : null,
            $command->phoneSecondary !== null ? PhoneNumber::of($command->phoneSecondary) : null,
            $command->address,
            $command->emergencyContactName,
            $command->emergencyContactPhone !== null ? PhoneNumber::of($command->emergencyContactPhone) : null,
            array_values($references),
        ));

        $this->helpers->save($helper);

        $this->recordEvents->record($helper->releaseEvents(), 'fleet.helper');
    }
}
