<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Event\CarrierRated;
use Logistics\Carriers\Domain\Event\CarrierReactivated;
use Logistics\Carriers\Domain\Event\CarrierRegistered;
use Logistics\Carriers\Domain\Event\CarrierSuspended;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\Model\Carrier;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\CarrierStatus;
use Logistics\Carriers\Domain\ValueObject\Rating;

function registrarTransportista(array $documents = []): Carrier
{
    return Carrier::register(
        CarrierId::generate(),
        'Transportes del Sur',
        'NIT-123456',
        $documents,
    );
}

it('registra un transportista activo y graba el evento', function (): void {
    $carrier = registrarTransportista();

    expect($carrier->status())->toBe(CarrierStatus::Active)
        ->and($carrier->name())->toBe('Transportes del Sur')
        ->and($carrier->taxId())->toBe('NIT-123456')
        ->and($carrier->averageRating())->toBeNull()
        ->and($carrier->ratingCount())->toBe(0);

    $events = $carrier->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CarrierRegistered::class)
        ->and($events[0]->eventName())->toBe('carriers.carrier_registered');
});

it('rechaza registrar sin nombre o sin NIT', function (callable $build, string $reason): void {
    expect($build)->toThrow(CarriersException::class, $reason);
})->with([
    'sin nombre' => [fn () => Carrier::register(CarrierId::generate(), '  ', 'NIT-1'), 'nombre'],
    'sin NIT' => [fn () => Carrier::register(CarrierId::generate(), 'ACME', '  '), 'NIT'],
]);

it('se suspende y se reactiva grabando los cambios', function (): void {
    $carrier = registrarTransportista();
    $carrier->releaseEvents();

    $carrier->suspend();
    expect($carrier->status())->toBe(CarrierStatus::Suspended);

    $carrier->reactivate();
    expect($carrier->status())->toBe(CarrierStatus::Active);

    $events = $carrier->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(CarrierSuspended::class)
        ->and($events[1])->toBeInstanceOf(CarrierReactivated::class);
});

it('es idempotente al suspender dos veces', function (): void {
    $carrier = registrarTransportista();
    $carrier->releaseEvents();

    $carrier->suspend();
    $carrier->releaseEvents();
    $carrier->suspend();

    expect($carrier->releaseEvents())->toBe([]);
});

it('recalcula el promedio de calificación y graba el evento', function (): void {
    $carrier = registrarTransportista();
    $carrier->releaseEvents();

    $carrier->addRating(Rating::of(4));
    expect($carrier->averageRating())->toBe(4.0)
        ->and($carrier->ratingCount())->toBe(1);

    $carrier->addRating(Rating::of(2));
    expect($carrier->averageRating())->toBe(3.0)
        ->and($carrier->ratingCount())->toBe(2);

    $carrier->addRating(Rating::of(5));
    expect($carrier->averageRating())->toBe(3.67)
        ->and($carrier->ratingCount())->toBe(3);

    $events = $carrier->releaseEvents();
    expect($events)->toHaveCount(3)
        ->and($events[0])->toBeInstanceOf(CarrierRated::class)
        ->and($events[2]->averageRating)->toBe(3.67)
        ->and($events[2]->ratingCount)->toBe(3);
});

it('rechaza calificaciones fuera del rango 1..5', function (int $value): void {
    expect(fn () => Rating::of($value))->toThrow(CarriersException::class);
})->with([0, 6, -1, 10]);
