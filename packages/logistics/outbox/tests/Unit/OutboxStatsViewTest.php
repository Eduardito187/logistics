<?php

declare(strict_types=1);

use Logistics\Outbox\Application\ReadModel\OutboxStatsView;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;

/**
 * Cobertura del modelo de lectura OutboxStatsView: construcción desde conteos por estado, derivados
 * (total, backlog) y su forma primitiva toArray() (envelope del tablero). PHP puro, sin Laravel.
 */
it('arma los conteos desde el mapa por estado con faltantes en cero', function (): void {
    $stats = OutboxStatsView::fromCounts([
        OutboxStatus::Pending->value => 3,
        OutboxStatus::Failed->value => 2,
        // dispatched y dead_lettered ausentes: deben caer en cero.
    ]);

    expect($stats->pending)->toBe(3)
        ->and($stats->failed)->toBe(2)
        ->and($stats->dispatched)->toBe(0)
        ->and($stats->deadLettered)->toBe(0);
});

it('calcula el total y el backlog (cola viva)', function (): void {
    $stats = new OutboxStatsView(3, 2, 4, 1);

    expect($stats->total())->toBe(10)
        ->and($stats->backlog())->toBe(5);
});

it('se serializa a su forma primitiva con los derivados', function (): void {
    $stats = new OutboxStatsView(3, 2, 4, 1);

    expect($stats->toArray())->toBe([
        'pending' => 3,
        'failed' => 2,
        'dispatched' => 4,
        'dead_lettered' => 1,
        'backlog' => 5,
        'total' => 10,
    ]);
});
