<?php

declare(strict_types=1);

use Logistics\Coverage\Domain\ValueObject\AdminLevel;

/**
 * Semántica de la jerarquía geográfica Departamento > Provincia > Municipio > Ciudad > Zona > Microzona.
 */
it('ranks levels from broadest to most specific', function (): void {
    expect(AdminLevel::Department->rank())->toBe(0)
        ->and(AdminLevel::Province->rank())->toBe(1)
        ->and(AdminLevel::Municipality->rank())->toBe(2)
        ->and(AdminLevel::City->rank())->toBe(3)
        ->and(AdminLevel::Zone->rank())->toBe(4)
        ->and(AdminLevel::Microzone->rank())->toBe(5);
});

it('exposes the immediate parent level, with the department as root', function (): void {
    expect(AdminLevel::Department->parentLevel())->toBeNull()
        ->and(AdminLevel::Province->parentLevel())->toBe(AdminLevel::Department)
        ->and(AdminLevel::Municipality->parentLevel())->toBe(AdminLevel::Province)
        ->and(AdminLevel::City->parentLevel())->toBe(AdminLevel::Municipality)
        ->and(AdminLevel::Zone->parentLevel())->toBe(AdminLevel::City)
        ->and(AdminLevel::Microzone->parentLevel())->toBe(AdminLevel::Zone);
});

it('separates administrative levels from coverage geometries', function (): void {
    expect(AdminLevel::Department->isAdministrative())->toBeTrue()
        ->and(AdminLevel::City->isAdministrative())->toBeTrue()
        ->and(AdminLevel::Zone->isAdministrative())->toBeFalse()
        ->and(AdminLevel::Microzone->isAdministrative())->toBeFalse()
        ->and(AdminLevel::Zone->isCoverageGeometry())->toBeTrue()
        ->and(AdminLevel::City->isCoverageGeometry())->toBeFalse();
});

it('tells which levels contain which', function (): void {
    expect(AdminLevel::Department->contains(AdminLevel::City))->toBeTrue()
        ->and(AdminLevel::City->contains(AdminLevel::Department))->toBeFalse()
        ->and(AdminLevel::Province->contains(AdminLevel::Province))->toBeFalse()
        ->and(AdminLevel::Municipality->contains(AdminLevel::Microzone))->toBeTrue();
});

it('lists the administrative levels in order', function (): void {
    expect(AdminLevel::administrative())->toBe([
        AdminLevel::Department,
        AdminLevel::Province,
        AdminLevel::Municipality,
        AdminLevel::City,
    ]);
});
