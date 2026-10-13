<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Crea una zona nueva a partir de un anillo y reglas en forma primitiva, y la persiste.
 *
 * @implements CommandHandler<DefineZone>
 */
final readonly class DefineZoneHandler implements CommandHandler
{
    public function __construct(private ZoneRepository $zones) {}

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
    }
}
