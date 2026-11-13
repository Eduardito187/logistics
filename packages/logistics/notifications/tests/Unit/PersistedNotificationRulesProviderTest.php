<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Rules\DefaultNotificationRulesProvider;
use Logistics\Notifications\Infrastructure\Rules\PersistedNotificationRulesProvider;

/**
 * Repositorio de reglas que devuelve una política fija (o null) para probar la delegación.
 */
function persistedRulesRepo(?CascadePolicy $policy): MessageTypeRuleRepository
{
    return new class($policy) implements MessageTypeRuleRepository
    {
        public function __construct(private ?CascadePolicy $policy) {}

        public function policyFor(MessageType $messageType): ?CascadePolicy
        {
            return $this->policy;
        }

        public function save(MessageType $messageType, CascadePolicy $policy): void {}

        public function remove(MessageType $messageType): void {}

        public function all(): array
        {
            return [];
        }
    };
}

it('usa la política personalizada cuando el repositorio la tiene', function (): void {
    $custom = CascadePolicy::of([Channel::Email]);
    $provider = new PersistedNotificationRulesProvider(
        persistedRulesRepo($custom),
        new DefaultNotificationRulesProvider,
    );

    expect($provider->policyFor(MessageType::EnRoute))->toBe($custom);
});

it('cae al default del ADR cuando no hay regla configurada', function (): void {
    $provider = new PersistedNotificationRulesProvider(
        persistedRulesRepo(null),
        new DefaultNotificationRulesProvider,
    );

    $policy = $provider->policyFor(MessageType::EnRoute);

    expect($policy->channels)->toBe([
        Channel::Fcm,
        Channel::Telegram,
        Channel::Email,
        Channel::Sms,
    ]);
});
