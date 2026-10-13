<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Actualiza las reglas de una zona existente y publica sus eventos de dominio al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure).
 *
 * @implements CommandHandler<UpdateZoneRules>
 */
final readonly class UpdateZoneRulesHandler implements CommandHandler
{
    public function __construct(
        private ZoneRepository $zones,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateZoneRules);

        $zone = $this->zones->findById(ZoneId::fromString($command->zoneId));
        if ($zone === null) {
            throw CoverageException::because("zona inexistente: {$command->zoneId}");
        }

        $zone->updateRules(ZoneRules::fromArray($command->rules));
        $this->zones->save($zone);

        $this->recordEvents->record($zone->releaseEvents(), 'coverage.zone');
    }
}
