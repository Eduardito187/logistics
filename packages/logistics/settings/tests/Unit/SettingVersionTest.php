<?php

declare(strict_types=1);

use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Domain\SettingVersion;
use Logistics\Settings\Domain\ValueObject\SettingId;
use Logistics\Settings\Domain\ValueObject\SettingKey;

/**
 * Repositorio en memoria para testear la lógica de versionado y vigencia sin base de datos.
 */
final class InMemorySettingsRepository implements SettingsRepository
{
    /** @var list<SettingVersion> */
    private array $versions = [];

    public function nextVersionFor(SettingKey $key): int
    {
        $max = 0;
        foreach ($this->versions as $version) {
            if ($version->key->equals($key) && $version->version > $max) {
                $max = $version->version;
            }
        }

        return $max + 1;
    }

    public function add(SettingVersion $v): void
    {
        $this->versions[] = $v;
    }

    public function currentFor(SettingKey $key, DateTimeImmutable $at): ?SettingVersion
    {
        $current = null;
        foreach ($this->versions as $version) {
            if (!$version->key->equals($key) || !$version->isEffectiveAt($at)) {
                continue;
            }
            if ($current === null || $version->version > $current->version) {
                $current = $version;
            }
        }

        return $current;
    }

    public function historyFor(SettingKey $key): array
    {
        $matching = array_values(array_filter(
            $this->versions,
            static fn (SettingVersion $v): bool => $v->key->equals($key),
        ));
        usort($matching, static fn (SettingVersion $a, SettingVersion $b): int => $b->version <=> $a->version);

        return $matching;
    }
}

/**
 * @param array<string, mixed> $value
 */
function makeVersion(SettingsRepository $repo, SettingKey $key, array $value, string $effectiveFrom): SettingVersion
{
    $version = SettingVersion::create(
        SettingId::generate(),
        $key,
        $repo->nextVersionFor($key),
        $value,
        new DateTimeImmutable($effectiveFrom),
        new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        'tester',
    );
    $repo->add($version);

    return $version;
}

it('numera versiones de forma incremental por clave', function (): void {
    $repo = new InMemorySettingsRepository;
    $key = SettingKey::fromString('coverage.default_service_days');

    expect($repo->nextVersionFor($key))->toBe(1);
    makeVersion($repo, $key, ['days' => 3], '2026-01-01T00:00:00+00:00');
    expect($repo->nextVersionFor($key))->toBe(2);
    makeVersion($repo, $key, ['days' => 5], '2026-06-01T00:00:00+00:00');
    expect($repo->nextVersionFor($key))->toBe(3);
});

it('numera versiones de forma independiente entre claves distintas', function (): void {
    $repo = new InMemorySettingsRepository;
    $coverage = SettingKey::fromString('coverage.default_service_days');
    $tariff = SettingKey::fromString('tariff.base');

    makeVersion($repo, $coverage, ['days' => 3], '2026-01-01T00:00:00+00:00');
    makeVersion($repo, $coverage, ['days' => 4], '2026-02-01T00:00:00+00:00');

    expect($repo->nextVersionFor($tariff))->toBe(1);
});

it('devuelve el valor vigente según la fecha consultada', function (): void {
    $repo = new InMemorySettingsRepository;
    $key = SettingKey::fromString('coverage.default_service_days');

    makeVersion($repo, $key, ['days' => 3], '2026-01-01T00:00:00+00:00');
    makeVersion($repo, $key, ['days' => 5], '2026-06-01T00:00:00+00:00');

    expect($repo->currentFor($key, new DateTimeImmutable('2026-03-15T00:00:00+00:00'))->value)
        ->toBe(['days' => 3])
        ->and($repo->currentFor($key, new DateTimeImmutable('2026-09-15T00:00:00+00:00'))->value)
        ->toBe(['days' => 5]);
});

it('no devuelve nada antes de la primera fecha de vigencia', function (): void {
    $repo = new InMemorySettingsRepository;
    $key = SettingKey::fromString('coverage.default_service_days');

    makeVersion($repo, $key, ['days' => 3], '2026-06-01T00:00:00+00:00');

    expect($repo->currentFor($key, new DateTimeImmutable('2026-01-01T00:00:00+00:00')))->toBeNull();
});

it('toma la mayor versión cuando dos rigen a la misma fecha', function (): void {
    $repo = new InMemorySettingsRepository;
    $key = SettingKey::fromString('coverage.default_service_days');

    makeVersion($repo, $key, ['days' => 3], '2026-01-01T00:00:00+00:00');
    makeVersion($repo, $key, ['days' => 7], '2026-01-01T00:00:00+00:00');

    expect($repo->currentFor($key, new DateTimeImmutable('2026-01-01T00:00:00+00:00'))->value)
        ->toBe(['days' => 7]);
});

it('expone el historial de la versión más nueva a la más vieja', function (): void {
    $repo = new InMemorySettingsRepository;
    $key = SettingKey::fromString('coverage.default_service_days');

    makeVersion($repo, $key, ['days' => 3], '2026-01-01T00:00:00+00:00');
    makeVersion($repo, $key, ['days' => 5], '2026-06-01T00:00:00+00:00');

    $history = $repo->historyFor($key);

    expect($history)->toHaveCount(2)
        ->and($history[0]->version)->toBe(2)
        ->and($history[1]->version)->toBe(1);
});

it('rechaza una versión menor a 1', function (): void {
    SettingVersion::create(
        SettingId::generate(),
        SettingKey::fromString('coverage.default_service_days'),
        0,
        ['days' => 3],
        new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        null,
    );
})->throws(Logistics\Settings\Domain\Exception\SettingsException::class);
