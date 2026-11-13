<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\ValueObject\ChecklistItemCriticality;

/**
 * Criticidad de un ítem del checklist pre-salida: solo la crítica bloquea el despacho.
 */
it('expone una etiqueta en inglés por caso', function (ChecklistItemCriticality $criticality, string $label): void {
    expect($criticality->label())->toBe($label);
})->with([
    'crítica' => [ChecklistItemCriticality::Critical, 'Critical'],
    'mayor' => [ChecklistItemCriticality::Major, 'Major'],
    'menor' => [ChecklistItemCriticality::Minor, 'Minor'],
]);

it('solo la criticidad crítica bloquea el despacho', function (): void {
    expect(ChecklistItemCriticality::Critical->blocksDispatch())->toBeTrue()
        ->and(ChecklistItemCriticality::Major->blocksDispatch())->toBeFalse()
        ->and(ChecklistItemCriticality::Minor->blocksDispatch())->toBeFalse();
});
