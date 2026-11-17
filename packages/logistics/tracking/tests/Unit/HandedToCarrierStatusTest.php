<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

// Estado nuevo «entregado a la transportadora» (ola 1): se puede ENTRAR desde los estados operativos
// (procesando, en curso, cargado, en camino), nunca desde pendiente ni desde un terminal; y se puede SALIR
// hacia el resto del ciclo (en camino, entregado, fallido, reprogramado, anulado). No es terminal.

it('admite entrar a HandedToCarrier desde los estados operativos', function (VisibleStatus $from): void {
    expect($from->canTransitionTo(VisibleStatus::HandedToCarrier))->toBeTrue();
})->with([
    VisibleStatus::Processing,
    VisibleStatus::InProgress,
    VisibleStatus::Loaded,
    VisibleStatus::OnTheWay,
]);

it('no admite entrar a HandedToCarrier desde pendiente ni desde un estado cerrado', function (VisibleStatus $from): void {
    expect($from->canTransitionTo(VisibleStatus::HandedToCarrier))->toBeFalse();
})->with([
    VisibleStatus::Pending,
    VisibleStatus::Delivered,
    VisibleStatus::Cancelled,
    VisibleStatus::Returned,
]);

it('admite salir de HandedToCarrier hacia el resto del ciclo', function (VisibleStatus $to): void {
    expect(VisibleStatus::HandedToCarrier->canTransitionTo($to))->toBeTrue();
})->with([
    VisibleStatus::OnTheWay,
    VisibleStatus::Delivered,
    VisibleStatus::Failed,
    VisibleStatus::Rescheduled,
    VisibleStatus::Cancelled,
]);

it('sigue prohibiendo salir de HandedToCarrier hacia pasos operativos previos o a devuelto', function (VisibleStatus $to): void {
    expect(VisibleStatus::HandedToCarrier->canTransitionTo($to))->toBeFalse();
})->with([
    VisibleStatus::Pending,
    VisibleStatus::Processing,
    VisibleStatus::InProgress,
    VisibleStatus::Loaded,
    VisibleStatus::Returned,
]);

it('HandedToCarrier no es terminal y tiene etiqueta de cliente en español', function (): void {
    expect(VisibleStatus::HandedToCarrier->isTerminal())->toBeFalse()
        ->and(VisibleStatus::HandedToCarrier->label())->toBe('Entregado a la transportadora');
});
