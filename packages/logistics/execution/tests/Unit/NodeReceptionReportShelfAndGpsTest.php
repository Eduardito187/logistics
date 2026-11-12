<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\NodeReceptionReconciled;
use Logistics\Execution\Domain\Event\NodeUnitShelved;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\NodeReception;
use Logistics\Execution\Domain\ValueObject\Corridor;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\MissingUnitDeclaration;
use Logistics\Execution\Domain\ValueObject\MissingUnitReason;
use Logistics\Execution\Domain\ValueObject\NodeReceptionId;
use Logistics\Execution\Domain\ValueObject\ReconciliationReport;
use Logistics\Execution\Domain\ValueObject\SurplusUnitDeclaration;

// Instante de conciliación/reubicación y foto del informe, repetidos en varios escenarios.
const SHELF_GPS_TEST_AT = '2026-11-12T10:00:00+00:00';
const SHELF_GPS_TEST_PHOTO = 'evidence/reception-1.jpg';

/**
 * Dominio de la recepción en nodo: informe del operador al conciliar (#11), ubicación en estante (#7) y GPS
 * opcional al recibir (#25). Sin base de datos.
 */
function nrReception(): NodeReception
{
    $reception = NodeReception::open(
        NodeReceptionId::generate(),
        'd1111111-1111-7111-8111-111111111111',
        'a1111111-1111-7111-8111-111111111111',
        'b2222222-2222-7222-8222-222222222222',
        Corridor::CdToStore,
        ['U-1', 'U-2', 'U-3'],
        new DateTimeImmutable('2026-11-12T08:00:00+00:00'),
    );
    $reception->releaseEvents();

    return $reception;
}

function nrDevice(): DeviceContext
{
    return DeviceContext::of('device-01', 'user-07');
}

function nrReceive(NodeReception $reception, string $unitId, ?GeoPoint $geo = null): void
{
    $reception->receiveUnit($unitId, $geo, nrDevice(), new DateTimeImmutable('2026-11-12T09:00:00+00:00'), 'k-'.$unitId);
}

it('records the operator report on reconcile and keeps only real discrepancies', function (): void {
    $reception = nrReception();
    nrReceive($reception, 'U-1');
    nrReceive($reception, 'U-9'); // sobrante
    $reception->releaseEvents();

    $reception->reconcile(nrDevice(), new DateTimeImmutable(SHELF_GPS_TEST_AT), ReconciliationReport::of(
        '  faltó una caja  ',
        SHELF_GPS_TEST_PHOTO,
        [
            MissingUnitDeclaration::of('U-2', MissingUnitReason::Damaged),
            MissingUnitDeclaration::of('U-1', MissingUnitReason::NotArrived), // sí llegó: se descarta
        ],
        [
            SurplusUnitDeclaration::of('U-9', 'e1111111-1111-7111-8111-111111111111'),
            SurplusUnitDeclaration::of('U-2'), // es faltante, no sobrante: se descarta
        ],
    ));

    $report = $reception->reconciliationReport();
    expect($report->notes)->toBe('faltó una caja')
        ->and($report->photoPath)->toBe(SHELF_GPS_TEST_PHOTO)
        ->and($report->missingToArray())->toBe([['unit_id' => 'U-2', 'reason' => 'damaged']])
        ->and($report->surplusToArray())->toBe([['unit_id' => 'U-9', 'belongs_to_shipment_id' => 'e1111111-1111-7111-8111-111111111111']])
        ->and($report->reasonFor('U-3'))->toBeNull();

    $events = $reception->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(NodeReceptionReconciled::class);

    /** @var NodeReceptionReconciled $event */
    $event = $events[0];
    expect($event->missingUnitIds)->toBe(['U-2', 'U-3'])
        ->and($event->payload()['missing_units'])->toBe([['unit_id' => 'U-2', 'reason' => 'damaged']])
        ->and($event->payload()['notes'])->toBe('faltó una caja')
        ->and($event->payload()['photo_path'])->toBe(SHELF_GPS_TEST_PHOTO);
});

it('reconciles without a report exactly as before (empty report, additive payload)', function (): void {
    $reception = nrReception();
    $reception->reconcile(nrDevice(), new DateTimeImmutable(SHELF_GPS_TEST_AT));

    expect($reception->reconciliationReport()->isEmpty())->toBeTrue();

    /** @var NodeReceptionReconciled $event */
    $event = $reception->releaseEvents()[0];
    expect($event->payload()['missing_units'])->toBe([])
        ->and($event->payload()['surplus_units'])->toBe([])
        ->and($event->payload()['notes'])->toBeNull();
});

it('does not overwrite the report on a second reconcile (idempotent)', function (): void {
    $reception = nrReception();
    $reception->reconcile(nrDevice(), new DateTimeImmutable(SHELF_GPS_TEST_AT), ReconciliationReport::of('primera'));
    $reception->releaseEvents();

    $reception->reconcile(nrDevice(), new DateTimeImmutable('2026-11-12T11:00:00+00:00'), ReconciliationReport::of('segunda'));

    expect($reception->reconciliationReport()->notes)->toBe('primera')
        ->and($reception->releaseEvents())->toBe([]);
});

it('shelves a unit, is idempotent on the same location and ignores stale relocations', function (): void {
    $reception = nrReception();

    expect($reception->assignShelfLocation('U-1', ' A-03-2 ', nrDevice(), new DateTimeImmutable('2026-11-12T09:30:00+00:00')))->toBeTrue();
    $events = $reception->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(NodeUnitShelved::class);

    /** @var NodeUnitShelved $shelved */
    $shelved = $events[0];
    expect($shelved->location)->toBe('A-03-2')->and($shelved->previousLocation)->toBeNull();

    // Misma ubicación: no cambia ni emite.
    expect($reception->assignShelfLocation('U-1', 'A-03-2', nrDevice(), new DateTimeImmutable('2026-11-12T09:40:00+00:00')))->toBeFalse()
        ->and($reception->releaseEvents())->toBe([]);

    // Reubicación con hora ANTERIOR a la vigente (reenvío tardío): se ignora.
    expect($reception->assignShelfLocation('U-1', 'B-01-1', nrDevice(), new DateTimeImmutable('2026-11-12T09:00:00+00:00')))->toBeFalse();

    // Reubicación posterior: cambia y recuerda la anterior.
    expect($reception->assignShelfLocation('U-1', 'B-01-1', nrDevice(), new DateTimeImmutable(SHELF_GPS_TEST_AT)))->toBeTrue();
    /** @var NodeUnitShelved $moved */
    $moved = $reception->releaseEvents()[0];
    expect($moved->previousLocation)->toBe('A-03-2')
        ->and($reception->shelfLocationOf('U-1')?->location)->toBe('B-01-1');
});

it('allows shelving after reconciliation and for received surplus units', function (): void {
    $reception = nrReception();
    nrReceive($reception, 'U-9'); // sobrante recibido
    $reception->reconcile(nrDevice(), new DateTimeImmutable(SHELF_GPS_TEST_AT));

    expect($reception->assignShelfLocation('U-9', 'SOBRANTES', nrDevice(), new DateTimeImmutable('2026-11-12T10:05:00+00:00')))->toBeTrue();
});

it('rejects shelving a unit that does not belong to the reception', function (): void {
    nrReception()->assignShelfLocation('U-404', 'A-01', nrDevice(), new DateTimeImmutable(SHELF_GPS_TEST_AT));
})->throws(ExecutionException::class);

it('rejects an empty or too long shelf location', function (string $location): void {
    nrReception()->assignShelfLocation('U-1', $location, nrDevice(), new DateTimeImmutable(SHELF_GPS_TEST_AT));
})->with(['   ', str_repeat('X', 65)])->throws(ExecutionException::class);

it('receives a unit without GPS and never invents a position', function (): void {
    $reception = nrReception();
    nrReceive($reception, 'U-1');

    $unit = $reception->receivedUnits()[0];
    expect($unit->geo)->toBeNull()
        ->and($unit->accuracyM)->toBeNull()
        ->and($reception->receivedUnitIds())->toBe(['U-1']);
});

it('keeps accuracy and capture time only when there is a GPS fix', function (): void {
    $reception = nrReception();
    $reception->receiveUnit(
        'U-1',
        GeoPoint::of(-17.78, -63.18),
        nrDevice(),
        new DateTimeImmutable('2026-11-12T09:00:00+00:00'),
        'k-gps',
        12.5,
        new DateTimeImmutable('2026-11-12T08:59:50+00:00'),
    );

    $unit = $reception->receivedUnits()[0];
    expect($unit->geo?->lat)->toBe(-17.78)
        ->and($unit->accuracyM)->toBe(12.5)
        ->and($unit->locationCapturedAt?->format(DATE_ATOM))->toBe('2026-11-12T08:59:50+00:00');
});
