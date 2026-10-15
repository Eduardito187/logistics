<?php

declare(strict_types=1);

use Logistics\Tracking\Application\ReadModel\PublicUnitView;
use Logistics\Tracking\Domain\Model\UnitTracking;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;
use Logistics\Tracking\Domain\ValueObject\UnitTrackingId;

function unitWithStatus(UnitStatus $status): UnitTracking
{
    return UnitTracking::reconstitute(
        UnitTrackingId::generate(),
        'ship-1',
        'unit-1',
        $status,
        null,
        null,
    );
}

it('exposes label, status and a Spanish message, without internal keys', function (): void {
    $view = PublicUnitView::fromUnit(unitWithStatus(UnitStatus::Loaded), 'Bulto 1 de 2');

    expect($view->toArray())->toBe([
        'label' => 'Bulto 1 de 2',
        'status' => 'LOADED',
        'status_message' => 'Cargado en el camión.',
    ]);
});

it('phrases a message for every unit status in Spanish', function (): void {
    $message = fn (UnitStatus $status): string => PublicUnitView::fromUnit(unitWithStatus($status), 'Bulto')->statusMessage;

    expect($message(UnitStatus::Registered))->toBe('Registrado.')
        ->and($message(UnitStatus::Prepared))->toBe('Preparado en bodega.')
        ->and($message(UnitStatus::Loaded))->toBe('Cargado en el camión.')
        ->and($message(UnitStatus::Unloaded))->toBe('Descargado en una parada.')
        ->and($message(UnitStatus::Received))->toBe('Recibido en el punto logístico.')
        ->and($message(UnitStatus::Delivered))->toBe('Entregado.')
        ->and($message(UnitStatus::Failed))->toBe('No se pudo entregar.');
});
