<?php

declare(strict_types=1);

use Logistics\Settings\Domain\Exception\SettingsException;
use Logistics\Settings\Domain\ValueObject\SettingKey;

it('acepta una clave namespaced válida', function (): void {
    expect(SettingKey::fromString('coverage.default_service_days')->value)
        ->toBe('coverage.default_service_days');
});

it('acepta varios segmentos y dígitos internos', function (): void {
    expect(SettingKey::fromString('tariff.zone_3.night_surcharge')->value)
        ->toBe('tariff.zone_3.night_surcharge');
});

it('compara por igualdad de valor', function (): void {
    expect(SettingKey::fromString('cutoff.scz_evening')->equals(SettingKey::fromString('cutoff.scz_evening')))
        ->toBeTrue();
});

it('rechaza claves mal formadas', function (string $raw): void {
    expect(fn () => SettingKey::fromString($raw))->toThrow(SettingsException::class);
})->with([
    'sin namespace (un solo segmento)' => ['coverage'],
    'vacía' => [''],
    'mayúsculas' => ['Coverage.default'],
    'empieza con dígito' => ['1coverage.default'],
    'punto final' => ['coverage.'],
    'punto inicial' => ['.coverage'],
    'guion medio' => ['coverage.default-days'],
    'espacios' => ['coverage .default'],
    'doble punto' => ['coverage..default'],
]);
