<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Command\BlockDispatchDay\BlockDispatchDayCommand;
use Logistics\Capacity\Application\Command\BlockDispatchDay\BlockDispatchDayHandler;
use Logistics\Capacity\Application\Command\UnblockDispatchDay\UnblockDispatchDayCommand;
use Logistics\Capacity\Application\Command\UnblockDispatchDay\UnblockDispatchDayHandler;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\DispatchBlackoutDay;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\Service\DispatchDayAvailability;
use Logistics\Capacity\Domain\ValueObject\DispatchBlackoutDayId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Outbox\Application\RecordDomainEvents;

/**
 * Repositorio de bloqueos en memoria con semántica real: clave natural (fecha|municipio) y un isBlocked
 * que considera el bloqueo global (municipio null) además del específico del municipio.
 */
function blackoutRepo(): DispatchBlackoutRepository
{
    return new class implements DispatchBlackoutRepository
    {
        /** @var array<string, DispatchBlackoutDay> */
        public array $store = [];

        public function nextIdentity(): DispatchBlackoutDayId
        {
            return DispatchBlackoutDayId::generate();
        }

        public function save(DispatchBlackoutDay $day): void
        {
            $this->store[$this->key($day->date()->toString(), $day->municipalityId())] = $day;
        }

        public function remove(DispatchBlackoutDay $day): void
        {
            unset($this->store[$this->key($day->date()->toString(), $day->municipalityId())]);
        }

        public function findByDate(SlotDate $date, ?string $municipalityId): ?DispatchBlackoutDay
        {
            return $this->store[$this->key($date->toString(), $municipalityId)] ?? null;
        }

        public function isBlocked(SlotDate $date, ?string $municipalityId): bool
        {
            if (isset($this->store[$this->key($date->toString(), null)])) {
                return true;
            }

            return $municipalityId !== null && isset($this->store[$this->key($date->toString(), $municipalityId)]);
        }

        public function listInRange(SlotDate $from, SlotDate $to, ?string $municipalityId = null): array
        {
            return array_values($this->store);
        }

        private function key(string $date, ?string $municipalityId): string
        {
            return $date.'|'.($municipalityId ?? '__global__');
        }
    };
}

it('blocks a day idempotently: re-blocking the same date/municipality reuses the id', function (): void {
    $repo = blackoutRepo();
    $handler = new BlockDispatchDayHandler($repo, new RecordDomainEvents(collectingOutbox(), capacityFixedClock()));

    $handler->handle(new BlockDispatchDayCommand('2026-12-25', null, 'Christmas'));
    $firstId = $repo->findByDate(SlotDate::fromString('2026-12-25'), null)?->id()->toString();

    $handler->handle(new BlockDispatchDayCommand('2026-12-25', null, 'Christmas (updated)'));
    $secondId = $repo->findByDate(SlotDate::fromString('2026-12-25'), null)?->id()->toString();

    expect($repo->store)->toHaveCount(1)
        ->and($secondId)->toBe($firstId)
        ->and($repo->findByDate(SlotDate::fromString('2026-12-25'), null)?->reason())->toBe('Christmas (updated)');
});

it('a global blackout blocks every municipality', function (): void {
    $repo = blackoutRepo();
    $block = new BlockDispatchDayHandler($repo, new RecordDomainEvents(collectingOutbox(), capacityFixedClock()));
    $block->handle(new BlockDispatchDayCommand('2026-12-25', null, 'National holiday'));

    $availability = new DispatchDayAvailability($repo);

    expect($availability->isBlocked(SlotDate::fromString('2026-12-25'), 'any-municipality'))->toBeTrue()
        ->and($availability->isAvailable(SlotDate::fromString('2026-12-25'), 'any-municipality'))->toBeFalse();
});

it('a municipality blackout blocks only that municipality', function (): void {
    $repo = blackoutRepo();
    $block = new BlockDispatchDayHandler($repo, new RecordDomainEvents(collectingOutbox(), capacityFixedClock()));
    $block->handle(new BlockDispatchDayCommand('2026-11-02', 'muni-a', 'Local festival'));

    $availability = new DispatchDayAvailability($repo);

    expect($availability->isBlocked(SlotDate::fromString('2026-11-02'), 'muni-a'))->toBeTrue()
        ->and($availability->isBlocked(SlotDate::fromString('2026-11-02'), 'muni-b'))->toBeFalse();
});

it('unblocks a day and emits the event', function (): void {
    $repo = blackoutRepo();
    $outbox = collectingOutbox();
    $block = new BlockDispatchDayHandler($repo, new RecordDomainEvents($outbox, capacityFixedClock()));
    $block->handle(new BlockDispatchDayCommand('2026-12-25', null, 'Christmas'));

    $unblock = new UnblockDispatchDayHandler($repo, new RecordDomainEvents($outbox, capacityFixedClock()));
    $unblock->handle(new UnblockDispatchDayCommand('2026-12-25', null));

    expect($repo->store)->toHaveCount(0)
        ->and($outbox->messages[count($outbox->messages) - 1]->eventName())->toBe('capacity.dispatch_day_unblocked');
});

it('fails to unblock a day that was not blocked', function (): void {
    $repo = blackoutRepo();
    $unblock = new UnblockDispatchDayHandler($repo, new RecordDomainEvents(collectingOutbox(), capacityFixedClock()));

    expect(fn () => $unblock->handle(new UnblockDispatchDayCommand('2026-12-25', null)))
        ->toThrow(CapacityException::class, 'no existe un bloqueo');
});
