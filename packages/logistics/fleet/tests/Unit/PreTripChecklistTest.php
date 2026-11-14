<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\PreTripChecklist;

/**
 * Checklist pre-salida: VO inmutable de ítems nombre => verificado.
 */
it('rechaza un checklist vacío', function (): void {
    expect(fn () => PreTripChecklist::of([]))
        ->toThrow(FleetException::class, 'no puede estar vacío');
});

it('rechaza un ítem con nombre vacío', function (): void {
    expect(fn () => PreTripChecklist::of(['   ' => false]))
        ->toThrow(FleetException::class, 'no puede tener nombre vacío');
});

it('marca un ítem devolviendo una copia nueva sin mutar el original', function (): void {
    $original = PreTripChecklist::of(['frenos' => false, 'luces' => false]);
    $marcado = $original->check('frenos');

    expect($original->toArray())->toBe(['frenos' => false, 'luces' => false])
        ->and($marcado->toArray())->toBe(['frenos' => true, 'luces' => false])
        ->and($marcado)->not->toBe($original);
});

it('rechaza marcar un ítem inexistente', function (): void {
    $checklist = PreTripChecklist::of(['frenos' => false]);

    expect(fn () => $checklist->check('motor'))
        ->toThrow(FleetException::class, 'El ítem no existe en el checklist');
});

it('informa los ítems pendientes y si está completo', function (): void {
    $checklist = PreTripChecklist::of(['frenos' => false, 'luces' => true]);

    expect($checklist->pending())->toBe(['frenos'])
        ->and($checklist->isComplete())->toBeFalse()
        ->and($checklist->check('frenos')->isComplete())->toBeTrue()
        ->and($checklist->check('frenos')->pending())->toBe([]);
});
