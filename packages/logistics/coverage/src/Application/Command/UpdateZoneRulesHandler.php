<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Actualiza las reglas de una zona existente.
 *
 * @implements CommandHandler<UpdateZoneRules>
 */
final readonly class UpdateZoneRulesHandler implements CommandHandler
{
    public function __construct(private ZoneRepository $zones) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateZoneRules);

        $zone = $this->zones->findById(ZoneId::fromString($command->zoneId));
        if ($zone === null) {
            throw CoverageException::because("zona inexistente: {$command->zoneId}");
        }

        $zone->updateRules(ZoneRules::fromArray($command->rules));
        $this->zones->save($zone);
    }
}
