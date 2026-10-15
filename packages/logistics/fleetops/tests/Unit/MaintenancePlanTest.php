<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\MaintenancePlanDeactivated;
use Logistics\FleetOps\Domain\Event\MaintenancePlanDefined;
use Logistics\FleetOps\Domain\Event\MaintenancePlanServiced;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\MaintenancePlan;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;

function definirPlan(?int $intervalKm = 10_000, ?int $intervalDays = 180): MaintenancePlan
{
    return MaintenancePlan::define(
        MaintenancePlanId::generate(),
        'vehicle-1',
        ServiceKind::OilChange,
        $intervalKm,
        $intervalDays,
    );
}

it('define un plan activo y graba el evento', function (): void {
    $plan = definirPlan();

    expect($plan->isActive())->toBeTrue()
        ->and($plan->nextDueKm())->toBeNull()
        ->and($plan->nextDueAt())->toBeNull();

    $events = $plan->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(MaintenancePlanDefined::class);
});

it('exige al menos un intervalo', function (): void {
    expect(fn () => definirPlan(null, null))->toThrow(FleetOpsException::class, 'al menos un intervalo');
});

it('rechaza intervalos no positivos', function (): void {
    expect(fn () => definirPlan(0, null))->toThrow(FleetOpsException::class, 'en km debe ser mayor que cero');
    expect(fn () => definirPlan(null, -1))->toThrow(FleetOpsException::class, 'en días debe ser mayor que cero');
});

it('al registrar el servicio recalcula el próximo vencimiento por km y por fecha', function (): void {
    $plan = definirPlan(10_000, 180);
    $plan->releaseEvents();

    $plan->recordServiceDone(Odometer::km(100_000), new DateTimeImmutable('2026-10-01T00:00:00+00:00'));

    // next_due_km = 100000 + 10000; next_due_at = 2026-10-01 + 180 días.
    expect($plan->lastDoneKm())->toBe(100_000)
        ->and($plan->nextDueKm())->toBe(110_000)
        ->and($plan->nextDueAt()?->format('Y-m-d'))->toBe('2027-03-30');

    $events = $plan->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(MaintenancePlanServiced::class);
});

it('sin odómetro no calcula el vencimiento por km aunque haya intervalo', function (): void {
    $plan = definirPlan(10_000, null);
    $plan->recordServiceDone(null, new DateTimeImmutable('2026-10-01T00:00:00+00:00'));

    expect($plan->nextDueKm())->toBeNull()
        ->and($plan->nextDueAt())->toBeNull();
});

it('isDue es verdadero cuando el odómetro alcanza el umbral por km', function (): void {
    $plan = definirPlan(10_000, null);
    $plan->recordServiceDone(Odometer::km(100_000), new DateTimeImmutable('2026-10-01T00:00:00+00:00'));

    $now = new DateTimeImmutable('2026-10-05T00:00:00+00:00');

    // next_due_km = 110000; con umbral 500, 109600 ya está por vencer, 109000 todavía no.
    expect($plan->isDue(Odometer::km(109_600), $now, 500, 7))->toBeTrue()
        ->and($plan->isDue(Odometer::km(109_000), $now, 500, 7))->toBeFalse();
});

it('isDue es verdadero cuando la fecha alcanza el umbral por días', function (): void {
    $plan = definirPlan(null, 180);
    $plan->recordServiceDone(null, new DateTimeImmutable('2026-10-01T00:00:00+00:00'));

    // next_due_at = 2027-03-30; con umbral 7 días, 2027-03-25 ya está por vencer.
    expect($plan->isDue(null, new DateTimeImmutable('2027-03-25T00:00:00+00:00'), 500, 7))->toBeTrue()
        ->and($plan->isDue(null, new DateTimeImmutable('2027-01-01T00:00:00+00:00'), 500, 7))->toBeFalse();
});

it('un plan inactivo nunca está vencido', function (): void {
    $plan = definirPlan(10_000, null);
    $plan->recordServiceDone(Odometer::km(100_000), new DateTimeImmutable('2026-10-01T00:00:00+00:00'));
    $plan->deactivate();

    expect($plan->isDue(Odometer::km(200_000), new DateTimeImmutable('2026-10-05T00:00:00+00:00'), 500, 7))->toBeFalse();
});

it('activa y desactiva de forma idempotente', function (): void {
    $plan = definirPlan();
    $plan->releaseEvents();

    $plan->activate(); // ya está activo → sin evento
    expect($plan->releaseEvents())->toBe([]);

    $plan->deactivate();
    expect($plan->isActive())->toBeFalse();
    $events = $plan->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(MaintenancePlanDeactivated::class);

    $plan->deactivate(); // ya está inactivo → sin evento
    expect($plan->releaseEvents())->toBe([]);
});
