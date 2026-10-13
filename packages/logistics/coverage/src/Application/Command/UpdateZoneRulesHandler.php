<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Coverage\Application\Contract\ZoneRuleSettings;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Actualiza las reglas de una zona existente, publica sus eventos de dominio al outbox y registra la
 * próxima versión de la regla en la configuración versionada (contexto settings) para el historial.
 *
 * El save del repositorio, el append al outbox y la publicación de la versión corren dentro de la misma
 * transacción que abre el decorador transaccional del CommandBus (Infrastructure): o se confirma todo
 * junto o nada, de modo que la copia vigente en jsonb y la versión en Configuración no se desincronizan.
 *
 * @implements CommandHandler<UpdateZoneRules>
 */
final readonly class UpdateZoneRulesHandler implements CommandHandler
{
    public function __construct(
        private ZoneRepository $zones,
        private RecordDomainEvents $recordEvents,
        private ZoneRuleSettings $ruleSettings,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateZoneRules);

        $zone = $this->zones->findById(ZoneId::fromString($command->zoneId));
        if ($zone === null) {
            throw CoverageException::because("zona inexistente: {$command->zoneId}");
        }

        $rules = ZoneRules::fromArray($command->rules);
        $zone->updateRules($rules);
        $this->zones->save($zone);

        $this->recordEvents->record($zone->releaseEvents(), 'coverage.zone');

        // Versión nueva de la regla en Configuración (historial + auditoría); la copia vigente queda en
        // el jsonb de la zona como proyección de estado para el admin tipado.
        $this->ruleSettings->publish($command->zoneId, $rules, $command->updatedBy);
    }
}
