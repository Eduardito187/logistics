<?php

declare(strict_types=1);

use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;

// Fecha de referencia común a todos los casos.
function fleetThresholdRef(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-13');
}

it('clasifica un documento vencido como expired', function (): void {
    $view = ExpiringDocumentView::forDriver('drv-1', 'Juan', 'driver_license', '2026-09-01', true);

    expect($view->thresholdAt(fleetThresholdRef()))->toBe(ExpiringDocumentView::THRESHOLD_EXPIRED);
});

it('clasifica un documento que vence dentro de 7 días como due_soon', function (): void {
    // 7 días exactos → sigue siendo ≤7 (due_soon).
    $sevenDays = ExpiringDocumentView::forDriver('drv-1', 'Juan', 'driver_license', '2026-10-20', false);
    // 5 días → due_soon.
    $fiveDays = ExpiringDocumentView::forDriver('drv-2', 'Ana', 'driver_license', '2026-10-18', false);

    expect($sevenDays->thresholdAt(fleetThresholdRef()))->toBe(ExpiringDocumentView::THRESHOLD_DUE_SOON)
        ->and($fiveDays->thresholdAt(fleetThresholdRef()))->toBe(ExpiringDocumentView::THRESHOLD_DUE_SOON);
});

it('clasifica un documento a más de 7 y hasta 30 días como upcoming', function (): void {
    // 8 días → ya fuera de due_soon.
    $eightDays = ExpiringDocumentView::forVehicle('veh-1', 'ABC-123', 'soat', '2026-10-21', false);
    // 23 días → upcoming.
    $farther = ExpiringDocumentView::forVehicle('veh-2', 'XYZ-789', 'soat', '2026-11-05', false);

    expect($eightDays->thresholdAt(fleetThresholdRef()))->toBe(ExpiringDocumentView::THRESHOLD_UPCOMING)
        ->and($farther->thresholdAt(fleetThresholdRef()))->toBe(ExpiringDocumentView::THRESHOLD_UPCOMING);
});

it('DocumentExpiry::status devuelve el estado para el badge de la licencia', function (): void {
    $ref = fleetThresholdRef();

    $expired = DocumentExpiry::of('driver_license', new DateTimeImmutable('2026-09-01'));
    $soon = DocumentExpiry::of('driver_license', new DateTimeImmutable('2026-11-01'));
    $valid = DocumentExpiry::of('driver_license', new DateTimeImmutable('2027-06-01'));

    expect($expired->status($ref))->toBe('expired')
        ->and($soon->status($ref))->toBe('expiring_soon')
        ->and($valid->status($ref))->toBe('valid');
});
