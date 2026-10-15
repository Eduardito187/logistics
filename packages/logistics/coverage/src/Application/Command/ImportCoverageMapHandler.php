<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\Service\GeoJsonCoverageParser;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Importa el mapa de cobertura: parsea el GeoJSON y crea una zona por cada feature.
 *
 * El parseo lo hace el servicio de dominio GeoJsonCoverageParser; el handler solo asigna ids,
 * persiste y publica al outbox los eventos de cada zona creada. Sin id en el GeoJSON de entrada:
 * cada import crea zonas nuevas. Todo corre en la transacción del decorador transaccional del bus.
 *
 * @implements CommandHandler<ImportCoverageMap>
 */
final readonly class ImportCoverageMapHandler implements CommandHandler
{
    public function __construct(
        private ZoneRepository $zones,
        private GeoJsonCoverageParser $parser,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ImportCoverageMap);

        foreach ($this->parser->parse($command->featureCollection) as $parsed) {
            $zone = Zone::define(
                $this->zones->nextIdentity(),
                $parsed->name,
                null,
                $parsed->polygon,
                $parsed->rules,
            );
            $this->zones->save($zone);
            $this->recordEvents->record($zone->releaseEvents(), 'coverage.zone');
        }
    }
}
