<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\ValueObject\QcOutcome;

it('expone los tres resultados de QC con su valor primitivo', function (): void {
    expect(QcOutcome::Accepted->value)->toBe('accepted')
        ->and(QcOutcome::Rejected->value)->toBe('rejected')
        ->and(QcOutcome::PartialDamage->value)->toBe('partial_damage')
        ->and(QcOutcome::cases())->toHaveCount(3);
});

it('da la etiqueta en inglés de cada resultado de QC', function (QcOutcome $outcome, string $label): void {
    expect($outcome->label())->toBe($label);
})->with([
    'accepted' => [QcOutcome::Accepted, 'Accepted'],
    'rejected' => [QcOutcome::Rejected, 'Rejected'],
    'partial damage' => [QcOutcome::PartialDamage, 'Partial damage'],
]);

it('reconstruye el enum desde su valor primitivo', function (): void {
    expect(QcOutcome::from('partial_damage'))->toBe(QcOutcome::PartialDamage);
});
