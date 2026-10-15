<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Query\GetCapacityRuleset\GetCapacityRulesetHandler;
use Logistics\Capacity\Application\Query\GetCapacityRuleset\GetCapacityRulesetQuery;

it('returns the current ruleset in its primitive form', function (): void {
    $ruleset = generatorRuleset();
    $handler = new GetCapacityRulesetHandler(
        rulesetProviderOf($ruleset),
        fixedClockAt('2026-01-01T00:00:00'),
    );

    $result = $handler->handle(new GetCapacityRulesetQuery);

    expect($result)->toBe($ruleset->toArray());
});
