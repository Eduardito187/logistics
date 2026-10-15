<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\BucketScope;

it('builds a municipality scope as the primary axis', function (): void {
    $scope = BucketScope::forMunicipality('11111111-1111-7111-8111-111111111111');

    expect($scope->isMunicipality())->toBeTrue()
        ->and($scope->isZone())->toBeFalse()
        ->and($scope->isNode())->toBeFalse()
        ->and($scope->municipalityId)->toBe('11111111-1111-7111-8111-111111111111')
        ->and($scope->nodeId)->toBeNull()
        ->and($scope->zoneId)->toBeNull();
});

it('reconstructs a municipality scope from nullable columns', function (): void {
    $scope = BucketScope::fromNullable(null, null, '22222222-2222-7222-8222-222222222222');

    expect($scope->isMunicipality())->toBeTrue()
        ->and($scope->municipalityId)->toBe('22222222-2222-7222-8222-222222222222');
});

it('keeps backward compatibility with node/zone two-argument reconstruction', function (): void {
    $node = BucketScope::fromNullable('33333333-3333-7333-8333-333333333333', null);
    $zone = BucketScope::fromNullable(null, '44444444-4444-7444-8444-444444444444');

    expect($node->isNode())->toBeTrue()
        ->and($node->municipalityId)->toBeNull()
        ->and($zone->isZone())->toBeTrue()
        ->and($zone->municipalityId)->toBeNull();
});

it('rejects a scope with more than one axis populated', function (): void {
    expect(fn () => BucketScope::fromNullable('a', 'b', 'c'))
        ->toThrow(CapacityException::class, 'exactamente un');
});

it('rejects a scope with no axis populated', function (): void {
    expect(fn () => BucketScope::fromNullable(null, null, null))
        ->toThrow(CapacityException::class, 'exactamente un');
});

it('treats empty strings as absent when reconstructing', function (): void {
    $scope = BucketScope::fromNullable('', '  ', '55555555-5555-7555-8555-555555555555');

    expect($scope->isMunicipality())->toBeTrue();
});
