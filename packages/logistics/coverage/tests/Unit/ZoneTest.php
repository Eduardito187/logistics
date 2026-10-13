<?php

declare(strict_types=1);

use Logistics\Coverage\Domain\Event\ZoneDefined;
use Logistics\Coverage\Domain\Event\ZoneRulesUpdated;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ServiceDays;
use Logistics\Coverage\Domain\ValueObject\TimeWindow;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;

/**
 * @return array<int, array{0: float, 1: float}>
 */
function zoneRing(): array
{
    return [[-63.2, -17.8], [-63.1, -17.8], [-63.1, -17.7], [-63.2, -17.7], [-63.2, -17.8]];
}

function sampleRules(): ZoneRules
{
    return ZoneRules::define(
        'CD-SCZ',
        ['STD', 'EXPRESS'],
        120,
        ['LG-GT32WPP' => 2],
        [TimeWindow::of('08:00', '18:00')],
        ServiceDays::of(1, 2, 3, 4, 5),
    );
}

it('defines a zone and records a ZoneDefined event', function (): void {
    $id = ZoneId::generate();
    $parent = GeoAreaId::generate();

    $zone = Zone::define($id, 'Centro', $parent, Polygon::fromRing(zoneRing()), sampleRules());

    expect($zone->id())->toBe($id)
        ->and($zone->name())->toBe('Centro')
        ->and($zone->parentAreaId())->toBe($parent)
        ->and($zone->isActive())->toBeTrue();

    $events = $zone->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ZoneDefined::class);

    /** @var ZoneDefined $event */
    $event = $events[0];
    expect($event->name)->toBe('Centro')
        ->and($event->eventName())->toBe('coverage.zone_defined')
        ->and($event->polygon['type'])->toBe('Polygon');
});

it('updates rules and records a ZoneRulesUpdated event', function (): void {
    $zone = Zone::define(ZoneId::generate(), 'Centro', null, Polygon::fromRing(zoneRing()), sampleRules());
    $zone->releaseEvents(); // descarta el ZoneDefined inicial

    $newRules = ZoneRules::define('CD-SCZ', ['STD'], 90, [], [], ServiceDays::everyday());
    $zone->updateRules($newRules);

    expect($zone->rules()->baseMinutes)->toBe(90)
        ->and($zone->rules()->services)->toBe(['STD']);

    $events = $zone->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ZoneRulesUpdated::class);
});

it('activates and deactivates a zone', function (): void {
    $zone = Zone::define(ZoneId::generate(), 'Centro', null, Polygon::fromRing(zoneRing()), sampleRules());

    $zone->deactivate();
    expect($zone->isActive())->toBeFalse();

    $zone->activate();
    expect($zone->isActive())->toBeTrue();
});

it('round-trips rules through their primitive form', function (): void {
    $rules = sampleRules();

    expect(ZoneRules::fromArray($rules->toArray())->toArray())->toBe($rules->toArray());
});
