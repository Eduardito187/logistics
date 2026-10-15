<?php

declare(strict_types=1);

use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\PlainTokenSecret;

// VO PlainTokenSecret: transporta el valor en claro "<prefix>.<secret>" una sola vez al emitir/rotar.

it('arma el token completo como prefix.secret', function (): void {
    $plain = PlainTokenSecret::of('itk_ab12cd34', 'super-secreto');

    expect($plain->prefix)->toBe('itk_ab12cd34')
        ->and($plain->secret)->toBe('super-secreto')
        ->and($plain->full())->toBe('itk_ab12cd34.super-secreto');
});

it('rechaza prefijo o secreto vacíos', function (string $prefix, string $secret): void {
    expect(fn () => PlainTokenSecret::of($prefix, $secret))->toThrow(AccessException::class);
})->with([
    'prefijo vacío' => ['  ', 'secreto'],
    'secreto vacío' => ['itk_x', '  '],
]);
