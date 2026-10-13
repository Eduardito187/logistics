<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\Service\KmlCoverageParser;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Importa el mapa de cobertura desde KML: parsea el documento y crea una zona por cada Placemark.
 *
 * El parseo lo hace el servicio de dominio KmlCoverageParser; el handler asigna ids, persiste y
 * publica al outbox los eventos de cada zona creada. Todo corre en la transacción del decorador
 * transaccional del bus.
 *
 * @implements CommandHandler<ImportCoverageKml>
 */
final readonly class ImportCoverageKmlHandler implements CommandHandler
{
    public function __construct(
        private ZoneRepository $zones,
        private KmlCoverageParser $parser,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ImportCoverageKml);

        foreach ($this->parser->parse($command->kml) as $parsed) {
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
