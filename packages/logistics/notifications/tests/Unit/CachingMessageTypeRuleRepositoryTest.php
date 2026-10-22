<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Cache\CachingMessageTypeRuleRepository;
use Logistics\Notifications\Infrastructure\Cache\NotificationsCacheKey;

/**
 * @param array<string, CascadePolicy> $policies
 */
function fakeMessageTypeRuleRepository(array $policies): MessageTypeRuleRepository
{
    return new class($policies) implements MessageTypeRuleRepository
    {
        public int $allCalls = 0;

        public int $saves = 0;

        public int $removes = 0;

        /** @param array<string, CascadePolicy> $policies */
        public function __construct(private array $policies) {}

        public function policyFor(MessageType $messageType): ?CascadePolicy
        {
            return $this->policies[$messageType->value] ?? null;
        }

        public function save(MessageType $messageType, CascadePolicy $policy): void
        {
            $this->saves++;
            $this->policies[$messageType->value] = $policy;
        }

        public function remove(MessageType $messageType): void
        {
            $this->removes++;
            unset($this->policies[$messageType->value]);
        }

        public function all(): array
        {
            $this->allCalls++;

            return $this->policies;
        }
    };
}

it('serves the second all() from cache and rehydrates each policy keyed by type', function (): void {
    $policy = CascadePolicy::of([Channel::Fcm, Channel::Sms], EscalateCondition::OnFailure);
    $inner = fakeMessageTypeRuleRepository([MessageType::EnRoute->value => $policy]);
    $repo = new CachingMessageTypeRuleRepository($inner, new CacheRepository(new ArrayStore));

    $first = $repo->all();
    $second = $repo->all();

    expect($inner->allCalls)->toBe(1)
        ->and($first)->toHaveKey(MessageType::EnRoute->value)
        ->and($second[MessageType::EnRoute->value])->toBeInstanceOf(CascadePolicy::class)
        ->and($second[MessageType::EnRoute->value]->first())->toBe(Channel::Fcm);
});

it('stores the primitive array of each policy, never the object (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $policy = CascadePolicy::of([Channel::Fcm, Channel::Sms], EscalateCondition::OnFailure);
    $repo = new CachingMessageTypeRuleRepository(
        fakeMessageTypeRuleRepository([MessageType::EnRoute->value => $policy]),
        $cache,
    );

    $repo->all();

    $stored = $cache->get(NotificationsCacheKey::typeRulesAll());
    expect($stored)->toBeArray()
        ->and($stored[MessageType::EnRoute->value])->toBeArray()
        ->and($stored[MessageType::EnRoute->value]['channels'])->toBe(['FCM', 'SMS'])
        ->and($stored[MessageType::EnRoute->value]['escalate_when'])->toBe('ON_FAILURE');
});

it('invalidates the cached list on save and on remove', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $policy = CascadePolicy::of([Channel::Fcm], EscalateCondition::NoOpen);
    $repo = new CachingMessageTypeRuleRepository(
        fakeMessageTypeRuleRepository([MessageType::EnRoute->value => $policy]),
        $cache,
    );

    $repo->all();
    expect($cache->has(NotificationsCacheKey::typeRulesAll()))->toBeTrue();
    $repo->save(MessageType::Delivered, $policy);
    expect($cache->has(NotificationsCacheKey::typeRulesAll()))->toBeFalse();

    $repo->all();
    expect($cache->has(NotificationsCacheKey::typeRulesAll()))->toBeTrue();
    $repo->remove(MessageType::Delivered);
    expect($cache->has(NotificationsCacheKey::typeRulesAll()))->toBeFalse();
});
