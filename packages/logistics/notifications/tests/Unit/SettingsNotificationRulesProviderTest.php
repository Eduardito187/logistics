<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Rules\DefaultNotificationRulesProvider;
use Logistics\Notifications\Infrastructure\Rules\SettingsNotificationRulesProvider;
use Logistics\Notifications\Tests\Unit\StubCascadeRuleSettings;

it('usa el override versionado de Configuración cuando existe', function (): void {
    $override = CascadePolicy::of([Channel::Email, Channel::Sms], EscalateCondition::OnFailure);
    $provider = new SettingsNotificationRulesProvider(
        new StubCascadeRuleSettings(['EN_ROUTE' => $override]),
        new DefaultNotificationRulesProvider,
    );

    expect($provider->policyFor(MessageType::EnRoute))->toBe($override);
});

it('cae a la cascada por defecto del ADR 006 para los tipos sin override', function (): void {
    $provider = new SettingsNotificationRulesProvider(
        new StubCascadeRuleSettings,
        new DefaultNotificationRulesProvider,
    );

    $policy = $provider->policyFor(MessageType::Arriving);

    // El default arranca por Fcm (canal más barato) y escala cuando no hay apertura.
    expect($policy->first())->toBe(Channel::Fcm)
        ->and($policy->escalateWhen)->toBe(EscalateCondition::NoOpen);
});
