<?php

declare(strict_types=1);

use Logistics\Tracking\Application\ReadModel\SatisfactionSummaryView;

// ReadModel del resumen de satisfacción: su forma primitiva (regla 7: se cachea el array, no el objeto).

it('serializa el resumen a su forma primitiva', function (): void {
    $view = new SatisfactionSummaryView(
        responseCount: 12,
        averageRating: 4.25,
        nps: 33,
        promoters: 6,
        passives: 4,
        detractors: 2,
        recentComments: [
            ['rating' => 5, 'comment' => 'Todo perfecto', 'reason' => null, 'submitted_at' => '2026-11-10T10:00:00+00:00'],
        ],
    );

    expect($view->toArray())->toBe([
        'response_count' => 12,
        'average_rating' => 4.25,
        'nps' => 33,
        'promoters' => 6,
        'passives' => 4,
        'detractors' => 2,
        'recent_comments' => [
            ['rating' => 5, 'comment' => 'Todo perfecto', 'reason' => null, 'submitted_at' => '2026-11-10T10:00:00+00:00'],
        ],
    ]);
});

it('conserva los nulos cuando todavía no hay respuestas', function (): void {
    $view = new SatisfactionSummaryView(0, null, null, 0, 0, 0, []);

    expect($view->toArray())->toMatchArray([
        'response_count' => 0,
        'average_rating' => null,
        'nps' => null,
        'recent_comments' => [],
    ]);
});
