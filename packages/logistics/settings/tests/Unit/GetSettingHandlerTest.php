<?php

declare(strict_types=1);

use Logistics\Settings\Application\Query\GetSetting\GetSettingHandler;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Domain\Exception\SettingsException;
use Logistics\Settings\Domain\SettingVersion;
use Logistics\Settings\Domain\ValueObject\SettingId;
use Logistics\Settings\Domain\ValueObject\SettingKey;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * GetSettingHandler en PHP puro (sin base): repositorio en memoria y reloj fijo. Cubre el camino feliz
 * («ahora» y a una fecha dada) y las dos ramas de error: tipo de consulta equivocado y fecha `at` inválida.
 */

/**
 * Reloj fijo para los tests.
 */
function getSettingClock(string $iso = '2026-01-01T00:00:00+00:00'): Clock
{
    return new class($iso) implements Clock
    {
        public function __construct(private string $iso) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->iso);
        }
    };
}

/**
 * Repositorio de settings en memoria que devuelve una versión fija para currentFor.
 */
function getSettingRepository(?SettingVersion $current): SettingsRepository
{
    return new class($current) implements SettingsRepository
    {
        public function __construct(private ?SettingVersion $current) {}

        public function nextVersionFor(SettingKey $key): int
        {
            return 1;
        }

        public function add(SettingVersion $v): void {}

        public function currentFor(SettingKey $key, DateTimeImmutable $at): ?SettingVersion
        {
            return $this->current;
        }

        public function historyFor(SettingKey $key): array
        {
            return $this->current === null ? [] : [$this->current];
        }
    };
}

it('devuelve el valor vigente de la clave', function (): void {
    $version = SettingVersion::create(
        SettingId::generate(),
        SettingKey::fromString('coverage.sample'),
        1,
        ['days' => 3],
        new DateTimeImmutable('2025-12-01T00:00:00+00:00'),
        new DateTimeImmutable('2025-12-01T00:00:00+00:00'),
        'tester',
    );
    $handler = new GetSettingHandler(getSettingRepository($version), getSettingClock());

    expect($handler->handle(new GetSettingQuery('coverage.sample')))->toBe(['days' => 3]);
});

it('devuelve null cuando no hay versión vigente', function (): void {
    $handler = new GetSettingHandler(getSettingRepository(null), getSettingClock());

    expect($handler->handle(new GetSettingQuery('coverage.sample', '2026-06-01T12:00:00+00:00')))->toBeNull();
});

it('rechaza una consulta de otro tipo', function (): void {
    $handler = new GetSettingHandler(getSettingRepository(null), getSettingClock());
    $other = new class implements Query {};

    expect(fn () => $handler->handle($other))->toThrow(SettingsException::class);
});

it('rechaza un `at` que no es una fecha válida', function (): void {
    $handler = new GetSettingHandler(getSettingRepository(null), getSettingClock());

    expect(fn () => $handler->handle(new GetSettingQuery('coverage.sample', 'no-es-fecha')))
        ->toThrow(SettingsException::class);
});
