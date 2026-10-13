<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Event\TariffSet;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\Model\Tariff;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\Money;
use Logistics\Carriers\Domain\ValueObject\TariffId;
use Logistics\Carriers\Domain\ValueObject\VehicleClass;

function definirTarifa(): Tariff
{
    return Tariff::define(
        TariffId::generate(),
        'carrier-123',
        CorridorRef::of('node-cd-scz', 'node-cd-lpz', 'cd_to_cd'),
        VehicleClass::Trailer,
        Money::of(350000, 'BOB'),
        new DateTimeImmutable('2026-10-13'),
    );
}

it('define una tarifa y graba el evento', function (): void {
    $tariff = definirTarifa();

    expect($tariff->carrierId())->toBe('carrier-123')
        ->and($tariff->corridor()->type)->toBe('cd_to_cd')
        ->and($tariff->vehicleClass())->toBe(VehicleClass::Trailer)
        ->and($tariff->price()->cents)->toBe(350000)
        ->and($tariff->price()->currency)->toBe('BOB');

    $events = $tariff->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TariffSet::class)
        ->and($events[0]->eventName())->toBe('carriers.tariff_set')
        ->and($events[0]->corridorType)->toBe('cd_to_cd');
});

it('rechaza un tipo de corredor desconocido', function (): void {
    expect(fn () => CorridorRef::of('a', 'b', 'teleport'))
        ->toThrow(CarriersException::class, 'Tipo de corredor inválido');
});

it('rechaza origen o destino vacío en el corredor', function (callable $build, string $reason): void {
    expect($build)->toThrow(CarriersException::class, $reason);
})->with([
    'origen vacío' => [fn () => CorridorRef::of('  ', 'b', 'cd_to_cd'), 'origen'],
    'destino vacío' => [fn () => CorridorRef::of('a', '  ', 'cd_to_cd'), 'destino'],
]);

it('está vigente en la fecha de vigencia y después, no antes', function (): void {
    $tariff = definirTarifa();

    expect($tariff->isEffectiveOn(new DateTimeImmutable('2026-10-13')))->toBeTrue()
        ->and($tariff->isEffectiveOn(new DateTimeImmutable('2026-11-01')))->toBeTrue()
        ->and($tariff->isEffectiveOn(new DateTimeImmutable('2026-10-01')))->toBeFalse();
});

it('compara el vocabulario de corredores por igualdad', function (): void {
    $a = CorridorRef::of('x', 'y', 'cd_to_store');
    $b = CorridorRef::of('x', 'y', 'cd_to_store');
    $c = CorridorRef::of('x', 'y', 'store_to_cd');

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});
