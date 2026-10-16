<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;
use Logistics\Inbound\Domain\ValueObject\AsnLine;
use Logistics\Inbound\Domain\ValueObject\QcOutcome;
use Logistics\Inbound\Domain\ValueObject\ReceiptLine;
use Logistics\Inbound\Domain\ValueObject\ReceivedQuantity;

it('arma una ventana de cita y expone su franja canónica', function (): void {
    $window = AppointmentWindow::of('2026-10-20', '08:00', '12:00');

    expect($window->date)->toBe('2026-10-20')
        ->and($window->timeSlot())->toBe('08:00-12:00')
        ->and(AppointmentWindow::fromPrimitives('2026-10-20', '08:00-12:00')->equals($window))->toBeTrue();
});

it('rechaza ventanas de cita mal formadas', function (callable $build): void {
    expect($build)->toThrow(InboundException::class);
})->with([
    'fecha inválida' => [fn () => AppointmentWindow::of('20-10-2026', '08:00', '12:00')],
    'hora inválida' => [fn () => AppointmentWindow::of('2026-10-20', '8am', '12:00')],
    'inicio >= fin' => [fn () => AppointmentWindow::of('2026-10-20', '12:00', '08:00')],
]);

it('exige sku y cantidad positiva en una línea de ASN', function (): void {
    expect(AsnLine::of('BEL-TV-55', 10)->expectedQuantity)->toBe(10)
        ->and(fn () => AsnLine::of('', 10))->toThrow(InboundException::class)
        ->and(fn () => AsnLine::of('X', 0))->toThrow(InboundException::class);
});

it('suma el total recibido y rechaza negativos o vacíos', function (): void {
    $quantity = ReceivedQuantity::of(9, 0, 1);

    expect($quantity->total())->toBe(10)
        ->and(fn () => ReceivedQuantity::of(-1))->toThrow(InboundException::class)
        ->and(fn () => ReceivedQuantity::of(0, 0, 0))->toThrow(InboundException::class);
});

it('valida la coherencia QC ↔ cantidades en una línea recibida', function (): void {
    $accepted = ReceiptLine::of('BEL-TV-55', ReceivedQuantity::of(10), QcOutcome::Accepted);
    expect($accepted->acceptedQuantity())->toBe(10);

    $partial = ReceiptLine::of('BEL-TV-55', ReceivedQuantity::of(9, 0, 1), QcOutcome::PartialDamage);
    expect($partial->acceptedQuantity())->toBe(9);
});

it('rechaza líneas recibidas incoherentes con su QC', function (callable $build): void {
    expect($build)->toThrow(InboundException::class);
})->with([
    'accepted con dañado' => [fn () => ReceiptLine::of('X', ReceivedQuantity::of(5, 0, 1), QcOutcome::Accepted)],
    'rejected con aceptado' => [fn () => ReceiptLine::of('X', ReceivedQuantity::of(1, 2, 0), QcOutcome::Rejected)],
    'partial sin dañado' => [fn () => ReceiptLine::of('X', ReceivedQuantity::of(5), QcOutcome::PartialDamage)],
]);
