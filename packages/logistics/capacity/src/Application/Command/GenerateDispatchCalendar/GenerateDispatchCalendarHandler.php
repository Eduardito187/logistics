<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\GenerateDispatchCalendar;

use DateTimeImmutable;
use Exception;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Contract\CapacityRulesetProvider;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\Service\DispatchCalendarPlanner;
use Logistics\Capacity\Domain\ValueObject\PlannedBucket;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Materializa el calendario de cupos del horizonte: proyecta las reglas vigentes con el planner y, por
 * cada cupo que debería existir y aún no existe, despacha DefineCapacity al CommandBus.
 *
 * Igual que el barrido de reservas de promise, este handler NO se despacha por el bus: lo invoca
 * directo la consola o el admin. Así cada DefineCapacity corre en su propia transacción (la que abre el
 * decorador transaccional del CommandBus) y publica su evento al outbox; un cupo que falle por una
 * carrera (alguien lo definió entremedio) no tumba la corrida entera. Es idempotente: re-ejecutarlo no
 * duplica cupos (se saltan los que ya existen por su clave natural). Expone los contadores de la última
 * corrida para poder reportarlos.
 *
 * @implements CommandHandler<GenerateDispatchCalendar>
 */
final class GenerateDispatchCalendarHandler implements CommandHandler
{
    private int $lastCreated = 0;

    private int $lastSkipped = 0;

    public function __construct(
        private readonly CapacityRulesetProvider $rules,
        private readonly DispatchCalendarPlanner $planner,
        private readonly CapacityRepository $buckets,
        private readonly CommandBus $commandBus,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof GenerateDispatchCalendar) {
            throw CapacityException::because('GenerateDispatchCalendarHandler solo maneja GenerateDispatchCalendar');
        }

        $this->lastCreated = 0;
        $this->lastSkipped = 0;

        $ruleset = $this->rules->current($this->parseAt($command->at));
        $from = $command->from !== null && $command->from !== ''
            ? SlotDate::fromString($command->from)
            : SlotDate::fromDateTime($this->clock->now());

        foreach ($this->planner->plan($ruleset, $from, $command->horizonDays) as $planned) {
            $this->materialize($planned);
        }
    }

    /**
     * Define el cupo planificado si todavía no existe. Las carreras (alguien lo definió entremedio) se
     * cuentan como saltadas, no como error de la corrida.
     */
    private function materialize(PlannedBucket $planned): void
    {
        $existing = $this->buckets->findBucket($planned->scope, $planned->date, $planned->slot, $planned->unit);
        if ($existing !== null) {
            $this->lastSkipped++;

            return;
        }

        try {
            $this->commandBus->dispatch(new DefineCapacityCommand(
                nodeId: $planned->scope->nodeId,
                zoneId: $planned->scope->zoneId,
                date: $planned->date->toString(),
                timeSlot: $planned->slot->toString(),
                unit: $planned->unit->value,
                total: $planned->total->value(),
            ));
            $this->lastCreated++;
        } catch (CapacityException) {
            // Carrera: el cupo se definió entre la lectura y el dispatch. No es un error del generador.
            $this->lastSkipped++;
        }
    }

    /**
     * Cupos definidos en la última corrida.
     */
    public function lastCreated(): int
    {
        return $this->lastCreated;
    }

    /**
     * Cupos que ya existían (o que una carrera definió) y por eso no se volvieron a crear.
     */
    public function lastSkipped(): int
    {
        return $this->lastSkipped;
    }

    private function parseAt(?string $raw): DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return $this->clock->now();
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            throw CapacityException::because("at no es una fecha válida: {$raw}");
        }
    }
}
