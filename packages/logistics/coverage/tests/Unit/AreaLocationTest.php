<?php

declare(strict_types=1);

use Logistics\Coverage\Domain\ValueObject\AreaLocation;

/**
 * Ubicación administrativa resuelta de un punto, con la cadena completa opcional.
 */
it('keeps the four-argument form backward compatible', function (): void {
    $location = new AreaLocation('dep-lp', 'La Paz', 'city-lp', 'La Paz');

    expect($location->departmentId)->toBe('dep-lp')
        ->and($location->cityId)->toBe('city-lp')
        ->and($location->provinceId)->toBeNull()
        ->and($location->municipalityId)->toBeNull();
});

it('carries the full administrative chain when provided', function (): void {
    $location = new AreaLocation(
        'dep-lp',
        'La Paz',
        'city-lp',
        'La Paz',
        'prov-murillo',
        'Pedro Domingo Murillo',
        'muni-nsdlp',
        'Nuestra Señora de La Paz',
    );

    expect($location->provinceId)->toBe('prov-murillo')
        ->and($location->provinceName)->toBe('Pedro Domingo Murillo')
        ->and($location->municipalityId)->toBe('muni-nsdlp')
        ->and($location->municipalityName)->toBe('Nuestra Señora de La Paz');
});

it('compares points along every level of the chain', function (): void {
    $a = new AreaLocation('dep-lp', 'La Paz', 'city-lp', 'La Paz', 'prov-murillo', 'Murillo', 'muni-lp', 'NSDLP');
    $sameCity = new AreaLocation('dep-lp', 'La Paz', 'city-lp', 'La Paz', 'prov-murillo', 'Murillo', 'muni-lp', 'NSDLP');
    $sameProvinceOtherCity = new AreaLocation('dep-lp', 'La Paz', 'city-ea', 'El Alto', 'prov-murillo', 'Murillo', 'muni-ea', 'El Alto');
    $otherDepartment = new AreaLocation('dep-sc', 'Santa Cruz', 'city-scz', 'Santa Cruz');

    expect($a->sameDepartmentAs($sameProvinceOtherCity))->toBeTrue()
        ->and($a->sameProvinceAs($sameProvinceOtherCity))->toBeTrue()
        ->and($a->sameMunicipalityAs($sameProvinceOtherCity))->toBeFalse()
        ->and($a->sameCityAs($sameCity))->toBeTrue()
        ->and($a->sameCityAs($sameProvinceOtherCity))->toBeFalse()
        ->and($a->sameDepartmentAs($otherDepartment))->toBeFalse()
        ->and($a->sameProvinceAs($otherDepartment))->toBeFalse();
});

it('treats missing identifiers as not matching', function (): void {
    $a = new AreaLocation('dep-lp', 'La Paz', null, null);
    $b = new AreaLocation('dep-lp', 'La Paz', null, null);

    expect($a->sameCityAs($b))->toBeFalse()
        ->and($a->sameProvinceAs($b))->toBeFalse()
        ->and($a->sameMunicipalityAs($b))->toBeFalse();
});
