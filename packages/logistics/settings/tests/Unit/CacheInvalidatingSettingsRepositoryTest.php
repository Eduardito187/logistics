<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Domain\SettingVersion;
use Logistics\Settings\Domain\ValueObject\SettingId;
use Logistics\Settings\Domain\ValueObject\SettingKey;
use Logistics\Settings\Infrastructure\Cache\CacheInvalidatingSettingsRepository;
use Logistics\Settings\Infrastructure\Cache\SettingsCacheKey;

function fakeSettingsRepository(): SettingsRepository
{
    return new class implements SettingsRepository
    {
        public int $adds = 0;

        public function nextVersionFor(SettingKey $key): int
        {
            return 1;
        }

        public function add(SettingVersion $v): void
        {
            $this->adds++;
        }

        public function currentFor(SettingKey $key, DateTimeImmutable $at): ?SettingVersion
        {
            return null;
        }

        public function historyFor(SettingKey $key): array
        {
            return [];
        }
    };
}

function sampleSettingVersion(string $key = 'promise.lead_days'): SettingVersion
{
    return SettingVersion::reconstitute(
        SettingId::generate(),
        SettingKey::fromString($key),
        1,
        ['lead_days' => 2],
        new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        'tester',
    );
}

it('delegates the add to the inner repository', function (): void {
    $inner = fakeSettingsRepository();
    $repo = new CacheInvalidatingSettingsRepository($inner, new CacheRepository(new ArrayStore));

    $repo->add(sampleSettingVersion());

    expect($inner->adds)->toBe(1);
});

it('forgets the current-value cache of the key on add', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CacheInvalidatingSettingsRepository(fakeSettingsRepository(), $cache);

    $cache->put(SettingsCacheKey::currentValue('promise.lead_days'), ['lead_days' => 1], 600);
    expect($cache->has(SettingsCacheKey::currentValue('promise.lead_days')))->toBeTrue();

    $repo->add(sampleSettingVersion('promise.lead_days'));

    expect($cache->has(SettingsCacheKey::currentValue('promise.lead_days')))->toBeFalse();
});
