<?php

declare(strict_types=1);

use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Application\Command\SetSetting\SetSettingHandler;
use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Domain\Exception\SettingsException;
use Logistics\Settings\Domain\SettingVersion;
use Logistics\Settings\Domain\ValueObject\SettingKey;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * SetSettingHandler en PHP puro (sin base): repositorio en memoria que registra lo agregado y reloj fijo.
 * Cubre el camino feliz (pide el número de versión y persiste), el uso de una fecha de vigencia provista
 * y las dos ramas de error: tipo de comando equivocado y `effectiveFrom` inválido.
 */

/**
 * Reloj fijo para los tests.
 */
function setSettingClock(string $iso = '2026-01-01T00:00:00+00:00'): Clock
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
 * Repositorio en memoria que captura la versión agregada y entrega un número de versión fijo.
 */
function setSettingRepository(int $next = 7): SettingsRepository
{
    return new class($next) implements SettingsRepository
    {
        public ?SettingVersion $added = null;

        public function __construct(private int $next) {}

        public function nextVersionFor(SettingKey $key): int
        {
            return $this->next;
        }

        public function add(SettingVersion $v): void
        {
            $this->added = $v;
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

it('persiste la próxima versión con el valor y el autor dados', function (): void {
    $repository = setSettingRepository(7);
    $handler = new SetSettingHandler($repository, setSettingClock());

    $handler->handle(new SetSettingCommand('coverage.sample', ['days' => 5], null, 'tester'));

    expect($repository->added)->toBeInstanceOf(SettingVersion::class)
        ->and($repository->added->version)->toBe(7)
        ->and($repository->added->key->value)->toBe('coverage.sample')
        ->and($repository->added->value)->toBe(['days' => 5])
        ->and($repository->added->createdBy)->toBe('tester');
});

it('usa la fecha de vigencia provista cuando viene en el comando', function (): void {
    $repository = setSettingRepository();
    $handler = new SetSettingHandler($repository, setSettingClock());

    $handler->handle(new SetSettingCommand('coverage.sample', ['days' => 1], '2026-03-15T09:00:00+00:00', null));

    expect($repository->added?->effectiveFrom->format(DateTimeImmutable::ATOM))
        ->toBe('2026-03-15T09:00:00+00:00');
});

it('rechaza un comando de otro tipo', function (): void {
    $handler = new SetSettingHandler(setSettingRepository(), setSettingClock());
    $other = new class implements Command {};

    expect(fn () => $handler->handle($other))->toThrow(SettingsException::class);
});

it('rechaza un effectiveFrom que no es una fecha válida', function (): void {
    $handler = new SetSettingHandler(setSettingRepository(), setSettingClock());

    expect(fn () => $handler->handle(new SetSettingCommand('coverage.sample', ['days' => 1], 'no-es-fecha', null)))
        ->toThrow(SettingsException::class);
});
