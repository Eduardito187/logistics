<?php

declare(strict_types=1);

use Logistics\Tracking\Application\ReadModel\PublicSurveyStateView;

// ReadModel del estado público de la encuesta: solo se puede calificar si es elegible y aún no respondió.

it('permite calificar cuando es elegible y no respondió', function (): void {
    $view = new PublicSurveyStateView(eligible: true, alreadySubmitted: false);

    expect($view->canSubmit())->toBeTrue()
        ->and($view->toArray())->toBe([
            'eligible' => true,
            'already_submitted' => false,
            'can_submit' => true,
        ]);
});

it('no permite calificar si ya respondió', function (): void {
    $view = new PublicSurveyStateView(eligible: true, alreadySubmitted: true);

    expect($view->canSubmit())->toBeFalse()
        ->and($view->toArray()['can_submit'])->toBeFalse();
});

it('no permite calificar si no es elegible', function (): void {
    $view = new PublicSurveyStateView(eligible: false, alreadySubmitted: false);

    expect($view->canSubmit())->toBeFalse();
});
