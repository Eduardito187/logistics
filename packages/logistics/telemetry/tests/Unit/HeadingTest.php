<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\ValueObject\Heading;

// VO Heading: rumbo en grados [0,360). PHP puro.

it('acepta rumbos dentro del rango [0,360)', function (int $degrees): void {
    expect(Heading::degrees($degrees)->degrees)->toBe($degrees);
})->with([0, 90, 180, 359]);

it('rechaza rumbos fuera del rango [0,360)', function (int $degrees): void {
    expect(fn () => Heading::degrees($degrees))->toThrow(TelemetryException::class);
})->with([-1, 360, 720]);
