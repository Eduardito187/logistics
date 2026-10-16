<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\Event\AsnAnnounced;
use Logistics\Inbound\Domain\Event\AsnCancelled;
use Logistics\Inbound\Domain\Event\AsnClosed;
use Logistics\Inbound\Domain\Event\AsnScheduled;
use Logistics\Inbound\Domain\Event\ShipmentArrived;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\Model\Asn;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Domain\ValueObject\AsnLine;
use Logistics\Inbound\Domain\ValueObject\AsnStatus;

function inboundAnnounceAsn(): Asn
{
    return Asn::announce(
        AsnId::generate(),
        '01931000-0000-7000-8000-000000000001',
        '01931001-0000-7000-8000-0000000000f1',
        [AsnLine::of('BEL-TV-55', 10)],
    );
}

function inboundWindow(): AppointmentWindow
{
    return AppointmentWindow::of('2026-10-20', '08:00', '12:00');
}

it('anuncia un ASN en Announced y graba el evento', function (): void {
    $asn = inboundAnnounceAsn();

    expect($asn->status())->toBe(AsnStatus::Announced)
        ->and($asn->lines())->toHaveCount(1);

    $events = $asn->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AsnAnnounced::class)
        ->and($events[0]->eventName())->toBe('inbound.asn_announced');
});

it('rechaza anunciar sin seller, sin almacén o sin líneas', function (callable $build): void {
    expect($build)->toThrow(InboundException::class);
})->with([
    'sin seller' => [fn () => Asn::announce(AsnId::generate(), '  ', 'wh', [AsnLine::of('X', 1)])],
    'sin almacén' => [fn () => Asn::announce(AsnId::generate(), 's', '  ', [AsnLine::of('X', 1)])],
    'sin líneas' => [fn () => Asn::announce(AsnId::generate(), 's', 'wh', [])],
]);

it('recorre el ciclo de vida completo grabando los eventos con evento', function (): void {
    $asn = inboundAnnounceAsn();
    $asn->releaseEvents();

    $asn->schedule(inboundWindow());
    $asn->recordArrival('TRUCK-9');
    $asn->startReceiving();
    $asn->markReceived();
    $asn->close();

    $events = $asn->releaseEvents();
    expect($events)->toHaveCount(3)
        ->and($events[0])->toBeInstanceOf(AsnScheduled::class)
        ->and($events[1])->toBeInstanceOf(ShipmentArrived::class)
        ->and($events[2])->toBeInstanceOf(AsnClosed::class)
        ->and($asn->status())->toBe(AsnStatus::Closed)
        ->and($asn->appointmentWindow()?->timeSlot())->toBe('08:00-12:00');
});

it('rechaza transiciones inválidas', function (): void {
    $asn = inboundAnnounceAsn();

    expect(fn () => $asn->recordArrival())->toThrow(InboundException::class)
        ->and(fn () => $asn->close())->toThrow(InboundException::class)
        ->and(fn () => $asn->markReceived())->toThrow(InboundException::class);
});

it('cancela desde un estado no terminal y es idempotente', function (): void {
    $asn = inboundAnnounceAsn();
    $asn->releaseEvents();

    $asn->cancel('seller no se presentó');
    expect($asn->status())->toBe(AsnStatus::Cancelled);

    $events = $asn->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AsnCancelled::class)
        ->and($events[0]->reason)->toBe('seller no se presentó');

    // Segunda cancelación: no re-emite.
    $asn->cancel();
    expect($asn->releaseEvents())->toBe([]);
});

it('no permite cancelar un ASN cerrado', function (): void {
    $asn = inboundAnnounceAsn();
    $asn->schedule(inboundWindow());
    $asn->recordArrival();
    $asn->markReceived();
    $asn->close();
    $asn->releaseEvents();

    expect(fn () => $asn->cancel())->toThrow(InboundException::class);
});
