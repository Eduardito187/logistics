<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Crea una zona nueva a partir de un anillo y reglas en forma primitiva, la persiste y publica sus
 * eventos de dominio al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<DefineZone>
 */
final readonly class DefineZoneHandler implements CommandHandler
{
    public function __construct(
        private ZoneRepository $zones,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DefineZone);

        $zone = Zone::define(
            $this->zones->nextIdentity(),
            $command->name,
            $command->parentAreaId !== null ? GeoAreaId::fromString($command->parentAreaId) : null,
            Polygon::fromRing($command->ring),
            ZoneRules::fromArray($command->rules),
        );

        $this->zones->save($zone);

        $this->recordEvents->record($zone->releaseEvents(), 'coverage.zone');
    }
}
