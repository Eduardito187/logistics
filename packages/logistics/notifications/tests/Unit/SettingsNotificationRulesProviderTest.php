<?php

declare(strict_types=1);

use Logistics\Notifications\Application\Contract\CascadeRuleSettings;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Rules\DefaultNotificationRulesProvider;
use Logistics\Notifications\Infrastructure\Rules\SettingsNotificationRulesProvider;

/**
 * Reglas de cascada configuradas en memoria: devuelve un override solo para los tipos que se le cargaron.
 */
final class StubCascadeRuleSettings implements CascadeRuleSettings
{
    /**
     * @param array<string, CascadePolicy> $overrides
     */
    public function __construct(private array $overrides = []) {}

    public function configured(): array
    {
        return $this->overrides;
    }

    public function policyFor(MessageType $messageType): ?CascadePolicy
    {
        return $this->overrides[$messageType->value] ?? null;
    }

    public function save(MessageType $messageType, CascadePolicy $policy, ?string $createdBy = null): void {}

    public function remove(MessageType $messageType, ?string $createdBy = null): void {}

    public function history(): array
    {
        return [];
    }
}

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

    // El default arranca por PushFcm (canal más barato) y escala cuando no hay apertura.
    expect($policy->first())->toBe(Channel::PushFcm)
        ->and($policy->escalateWhen)->toBe(EscalateCondition::NoOpen);
});
