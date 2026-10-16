<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Infrastructure\Settings\SettingsLabelSymbologyProvider;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Bus de consultas que siempre responde con un valor fijo (simula settings con/sin versión publicada).
 */
function symbologyQueryBusReturning(mixed $value): QueryBus
{
    return new class($value) implements QueryBus
    {
        public function __construct(private mixed $value) {}

        public function ask(Query $query): mixed
        {
            return $this->value;
        }
    };
}

/** Config del package con el valor por defecto de la simbología. */
function labelsConfig(string $default = 'both'): Repository
{
    return new Repository(['logistics-labels' => ['symbology' => $default]]);
}

it('uses the versioned settings value when a published version exists', function (): void {
    $provider = new SettingsLabelSymbologyProvider(
        symbologyQueryBusReturning(['symbology' => 'barcode']),
        labelsConfig('both'),
    );

    expect($provider->current(new DateTimeImmutable('2026-10-15T12:00:00')))
        ->toBe(LabelSymbology::Barcode);
});

it('falls back to the package config default when settings has no published version', function (): void {
    $provider = new SettingsLabelSymbologyProvider(
        symbologyQueryBusReturning(null),
        labelsConfig('qr'),
    );

    expect($provider->current(new DateTimeImmutable('2026-10-15T12:00:00')))
        ->toBe(LabelSymbology::Qr);
});

it('falls back to BOTH when neither settings nor config give a usable value', function (): void {
    $provider = new SettingsLabelSymbologyProvider(
        symbologyQueryBusReturning(['symbology' => 'nonsense']),
        new Repository(['logistics-labels' => []]),
    );

    expect($provider->current(new DateTimeImmutable('2026-10-15T12:00:00')))
        ->toBe(LabelSymbology::Both);
});
