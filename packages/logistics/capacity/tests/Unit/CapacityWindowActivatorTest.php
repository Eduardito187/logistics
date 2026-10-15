<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Application\Service\CapacityWindowActivator;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\DispatchBlackoutDay;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\Service\DispatchDayAvailability;
use Logistics\Capacity\Domain\ValueObject\ActivationWindow;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\DispatchBlackoutDayId;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;

const ACTIVATOR_MUNICIPALITY = '11111111-1111-7111-8111-111111111111';

/**
 * CommandBus en memoria que registra los DefineCapacity despachados y puede simular que ciertas fechas
 * ya tienen cupo (lanza CapacityException, como el handler real ante un duplicado).
 */
function activatorBus(array $existingDates = []): CommandBus
{
    return new class($existingDates) implements CommandBus
    {
        /** @var list<DefineCapacityCommand> */
        public array $defined = [];

        /** @param list<string> $existingDates */
        public function __construct(private array $existingDates) {}

        public function dispatch(Command $command): void
        {
            assert($command instanceof DefineCapacityCommand);
            if (in_array($command->date, $this->existingDates, true)) {
                throw CapacityException::because('ya existe un cupo para ese scope, fecha, franja y unidad');
            }
            $this->defined[] = $command;
        }
    };
}

/**
 * @param list<string> $blockedDates
 */
function activatorAvailability(array $blockedDates = []): DispatchDayAvailability
{
    $repo = new class($blockedDates) implements DispatchBlackoutRepository
    {
        /** @param list<string> $blockedDates */
        public function __construct(private array $blockedDates) {}

        public function nextIdentity(): DispatchBlackoutDayId
        {
            return DispatchBlackoutDayId::generate();
        }

        public function save(DispatchBlackoutDay $day): void {}

        public function remove(DispatchBlackoutDay $day): void {}

        public function findByDate(SlotDate $date, ?string $municipalityId): ?DispatchBlackoutDay
        {
            return null;
        }

        public function isBlocked(SlotDate $date, ?string $municipalityId): bool
        {
            return in_array($date->toString(), $this->blockedDates, true);
        }

        public function listInRange(SlotDate $from, SlotDate $to, ?string $municipalityId = null): array
        {
            return [];
        }
    };

    return new DispatchDayAvailability($repo);
}

/**
 * @return list<ActivationWindow>
 */
function activatorWindow(): array
{
    return [ActivationWindow::of(TimeSlot::fromString('08:00-12:00'), CapacityUnit::Deliveries, Quantity::of(40.0))];
}

it('creates buckets per municipality for eligible days and skips inactive weekdays and blocked days', function (): void {
    // Rango Lun 2026-11-02 … Dom 2026-11-08; días activos Lun-Vie; 2026-11-04 bloqueado.
    $bus = activatorBus();
    $activator = new CapacityWindowActivator($bus, activatorAvailability(['2026-11-04']));

    $result = $activator->activateChunk(
        ACTIVATOR_MUNICIPALITY,
        SlotDate::fromString('2026-11-02'),
        SlotDate::fromString('2026-11-08'),
        [1, 2, 3, 4, 5],
        activatorWindow(),
        maxDays: 7,
    );

    // Expectativa derivada de forma independiente con la misma lógica de días/bloqueos.
    $expectedCreated = 0;
    $expectedInactive = 0;
    $expectedBlocked = 0;
    $cursor = new DateTimeImmutable('2026-11-02');
    $end = new DateTimeImmutable('2026-11-08');
    while ($cursor <= $end) {
        $weekday = (int) $cursor->format('N');
        $iso = $cursor->format('Y-m-d');
        if (!in_array($weekday, [1, 2, 3, 4, 5], true)) {
            $expectedInactive++;
        } elseif ($iso === '2026-11-04') {
            $expectedBlocked++;
        } else {
            $expectedCreated++;
        }
        $cursor = $cursor->add(new DateInterval('P1D'));
    }

    expect($result->created)->toBe($expectedCreated)
        ->and($result->skippedInactive)->toBe($expectedInactive)
        ->and($result->skippedBlocked)->toBe($expectedBlocked)
        ->and($result->isDone())->toBeTrue()
        ->and($bus->defined)->toHaveCount($expectedCreated);

    // Todos los cupos creados llevan el municipio y ninguna otra dimensión de scope.
    foreach ($bus->defined as $command) {
        expect($command->municipalityId)->toBe(ACTIVATOR_MUNICIPALITY)
            ->and($command->nodeId)->toBeNull()
            ->and($command->zoneId)->toBeNull();
    }
});

it('is idempotent: days that already have a bucket are skipped, not duplicated', function (): void {
    // Todos los días activos; 2026-11-03 ya tiene cupo (el bus lanza como el handler real).
    $bus = activatorBus(['2026-11-03']);
    $activator = new CapacityWindowActivator($bus, activatorAvailability());

    $result = $activator->activateChunk(
        ACTIVATOR_MUNICIPALITY,
        SlotDate::fromString('2026-11-02'),
        SlotDate::fromString('2026-11-04'),
        [1, 2, 3, 4, 5, 6, 7],
        activatorWindow(),
        maxDays: 10,
    );

    // Tres días, uno ya existía → 2 creados, 1 saltado por existente.
    expect($result->created)->toBe(2)
        ->and($result->skippedExisting)->toBe(1)
        ->and($result->isDone())->toBeTrue();
});

it('chunks a large range: processes maxDays and returns the continuation cursor', function (): void {
    $bus = activatorBus();
    $activator = new CapacityWindowActivator($bus, activatorAvailability());

    // Rango de 10 días con lote de 4: procesa 2026-11-02..2026-11-05 y continúa en 2026-11-06.
    $result = $activator->activateChunk(
        ACTIVATOR_MUNICIPALITY,
        SlotDate::fromString('2026-11-02'),
        SlotDate::fromString('2026-11-11'),
        [1, 2, 3, 4, 5, 6, 7],
        activatorWindow(),
        maxDays: 4,
    );

    expect($result->isDone())->toBeFalse()
        ->and($result->nextFrom?->toString())->toBe('2026-11-06')
        ->and($bus->defined)->toHaveCount(4);
});
