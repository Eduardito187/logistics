<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\HelperAvailabilityChanged;
use Logistics\Fleet\Domain\Event\HelperRegistered;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\HelperId;

function registrarAyudante(bool $active = true): Helper
{
    return Helper::register(
        HelperId::generate(),
        'Mario Flores',
        '70012345',
        [DocumentExpiry::of('health_card', new DateTimeImmutable('2027-01-01'))],
        $active,
    );
}

it('registra un ayudante activo y graba el evento', function (): void {
    $helper = registrarAyudante();

    expect($helper->isActive())->toBeTrue()
        ->and($helper->name())->toBe('Mario Flores')
        ->and($helper->phone())->toBe('70012345');

    $events = $helper->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(HelperRegistered::class);
});

it('graba un evento al cambiar la disponibilidad', function (): void {
    $helper = registrarAyudante();
    $helper->releaseEvents();

    $helper->changeAvailability(false);

    expect($helper->isActive())->toBeFalse();
    $events = $helper->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(HelperAvailabilityChanged::class);
});

it('es idempotente: no graba evento si la disponibilidad no cambia', function (): void {
    $helper = registrarAyudante();
    $helper->releaseEvents();

    $helper->changeAvailability(true);

    expect($helper->releaseEvents())->toHaveCount(0);
});

it('lista los documentos por vencer dentro de la ventana', function (): void {
    $helper = registrarAyudante();

    $vencenPronto = $helper->documentsExpiringWithin(60, new DateTimeImmutable('2026-12-01'));
    expect($vencenPronto)->toHaveCount(1);

    $noVencen = $helper->documentsExpiringWithin(10, new DateTimeImmutable('2026-01-01'));
    expect($noVencen)->toHaveCount(0);
});
