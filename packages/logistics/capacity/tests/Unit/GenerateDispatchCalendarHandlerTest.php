<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Application\Command\GenerateDispatchCalendar\GenerateDispatchCalendar;
use Logistics\Capacity\Application\Command\GenerateDispatchCalendar\GenerateDispatchCalendarHandler;
use Logistics\Capacity\Domain\CapacityBucket;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Contract\CapacityRulesetProvider;
use Logistics\Capacity\Domain\Service\DispatchCalendarPlanner;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityBucketId;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reglas para el generador: todos los días de servicio, una ventana, una unidad, un scope de nodo.
 */
function generatorRuleset(int $horizon = 3): CapacityRuleset
{
    return CapacityRuleset::fromArray([
        'service_days' => [1, 2, 3, 4, 5, 6, 7],
        'order_cutoff' => '12:00',
        'horizon_days' => $horizon,
        'holidays' => [],
        'windows' => ['08:00-12:00'],
        'windows_by_weekday' => [],
        'totals' => ['deliveries' => 40],
        'totals_by_slot' => [],
        'scopes' => [['node_id' => '11111111-1111-7111-8111-111111111111', 'zone_id' => null]],
    ]);
}

function rulesetProviderOf(CapacityRuleset $ruleset): CapacityRulesetProvider
{
    return new class($ruleset) implements CapacityRulesetProvider
    {
        public function __construct(private CapacityRuleset $ruleset) {}

        public function current(DateTimeImmutable $at): CapacityRuleset
        {
            return $this->ruleset;
        }

        public function currentForMunicipality(?string $municipalityId, DateTimeImmutable $at): CapacityRuleset
        {
            return $this->ruleset;
        }
    };
}

function fixedClockAt(string $iso): Clock
{
    return new class($iso) implements Clock
    {
        public function __construct(private string $iso) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->iso);
        }
    };
}

/**
 * Repositorio en memoria: findBucket devuelve un bucket "existente" si su clave natural está en el
 * conjunto precargado; el resto devuelve null/vacío.
 *
 * @param list<string> $existingKeys claves naturales "scope|date|slot|unit" ya ocupadas
 */
function repositoryWithExisting(array $existingKeys): CapacityRepository
{
    return new class($existingKeys) implements CapacityRepository
    {
        /** @param list<string> $existingKeys */
        public function __construct(private array $existingKeys) {}

        public function nextIdentity(): CapacityBucketId
        {
            return CapacityBucketId::generate();
        }

        public function save(CapacityBucket $bucket): void {}

        public function findById(CapacityBucketId $id): ?CapacityBucket
        {
            return null;
        }

        public function findBucket(BucketScope $scope, SlotDate $date, TimeSlot $slot, CapacityUnit $unit): ?CapacityBucket
        {
            $key = ($scope->nodeId ?? $scope->zoneId).'|'.$date->toString().'|'.$slot->toString().'|'.$unit->value;
            if (!in_array($key, $this->existingKeys, true)) {
                return null;
            }

            return CapacityBucket::define(CapacityBucketId::generate(), $scope, $date, $slot, $unit, Quantity::of(40.0));
        }

        public function findBucketForUpdate(BucketScope $scope, SlotDate $date, TimeSlot $slot, CapacityUnit $unit): ?CapacityBucket
        {
            return $this->findBucket($scope, $date, $slot, $unit);
        }

        public function byDate(SlotDate $date): array
        {
            return [];
        }

        public function inboundByWarehouseAndDate(string $warehouseId, SlotDate $date): array
        {
            return [];
        }
    };
}

/**
 * Bus que registra cada comando despachado, para inspeccionarlos.
 *
 * @return object{commands: list<Command>}&CommandBus
 */
function recordingBus(): CommandBus
{
    return new class implements CommandBus
    {
        /** @var list<Command> */
        public array $commands = [];

        public function dispatch(Command $command): void
        {
            $this->commands[] = $command;
        }
    };
}

it('defines a DefineCapacity command for every missing bucket in the horizon', function (): void {
    $bus = recordingBus();
    $handler = new GenerateDispatchCalendarHandler(
        rulesetProviderOf(generatorRuleset(horizon: 3)),
        new DispatchCalendarPlanner,
        repositoryWithExisting([]),
        $bus,
        fixedClockAt('2026-03-02T09:00:00'),
    );

    $handler->handle(new GenerateDispatchCalendar);

    expect($handler->lastCreated())->toBe(3)
        ->and($handler->lastSkipped())->toBe(0)
        ->and($bus->commands)->toHaveCount(3);

    $first = $bus->commands[0];
    expect($first)->toBeInstanceOf(DefineCapacityCommand::class);
    assert($first instanceof DefineCapacityCommand);
    expect($first->nodeId)->toBe('11111111-1111-7111-8111-111111111111')
        ->and($first->date)->toBe('2026-03-02')
        ->and($first->timeSlot)->toBe('08:00-12:00')
        ->and($first->unit)->toBe('deliveries')
        ->and($first->total)->toBe(40.0);
});

it('starts from the clock date when no start date is given', function (): void {
    $bus = recordingBus();
    $handler = new GenerateDispatchCalendarHandler(
        rulesetProviderOf(generatorRuleset(horizon: 1)),
        new DispatchCalendarPlanner,
        repositoryWithExisting([]),
        $bus,
        fixedClockAt('2026-07-20T06:00:00'),
    );

    $handler->handle(new GenerateDispatchCalendar);

    $command = $bus->commands[0];
    assert($command instanceof DefineCapacityCommand);
    expect($command->date)->toBe('2026-07-20');
});

it('skips buckets that already exist and does not dispatch for them', function (): void {
    $existing = '11111111-1111-7111-8111-111111111111|2026-03-02|08:00-12:00|deliveries';
    $bus = recordingBus();
    $handler = new GenerateDispatchCalendarHandler(
        rulesetProviderOf(generatorRuleset(horizon: 3)),
        new DispatchCalendarPlanner,
        repositoryWithExisting([$existing]),
        $bus,
        fixedClockAt('2026-03-02T09:00:00'),
    );

    $handler->handle(new GenerateDispatchCalendar(from: '2026-03-02'));

    expect($handler->lastCreated())->toBe(2)
        ->and($handler->lastSkipped())->toBe(1)
        ->and($bus->commands)->toHaveCount(2);
});

it('respects the start date and horizon override from the command', function (): void {
    $bus = recordingBus();
    $handler = new GenerateDispatchCalendarHandler(
        rulesetProviderOf(generatorRuleset(horizon: 14)),
        new DispatchCalendarPlanner,
        repositoryWithExisting([]),
        $bus,
        fixedClockAt('2026-03-02T09:00:00'),
    );

    $handler->handle(new GenerateDispatchCalendar(from: '2026-05-10', horizonDays: 2));

    expect($bus->commands)->toHaveCount(2);
    $dates = array_map(static function (Command $c): string {
        assert($c instanceof DefineCapacityCommand);

        return $c->date;
    }, $bus->commands);
    expect($dates)->toBe(['2026-05-10', '2026-05-11']);
});
