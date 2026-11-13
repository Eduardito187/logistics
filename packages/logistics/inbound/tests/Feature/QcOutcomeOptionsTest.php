<?php

declare(strict_types=1);

use Logistics\Inbound\Presentation\Filament\Support\QcOutcomeOptions;

/**
 * Cobertura del helper de opciones de QC para los selects del admin. Es Feature porque usa el helper de
 * traducción `__()`, que requiere el contenedor de Laravel booteado.
 */
it('expone el mapa valor→etiqueta de los resultados de QC para el admin', function (): void {
    $options = QcOutcomeOptions::all();

    expect($options)->toHaveKeys(['accepted', 'rejected', 'partial_damage'])
        ->and($options)->toHaveCount(3)
        ->and($options['accepted'])->toBe('Accepted')
        ->and($options['rejected'])->toBe('Rejected')
        ->and($options['partial_damage'])->toBe('Partial damage');
});
