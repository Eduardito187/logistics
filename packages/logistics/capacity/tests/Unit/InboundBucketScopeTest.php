<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\ScopeKind;

it('builds an inbound receiving-dock scope with a warehouse and a dock', function (): void {
    $scope = BucketScope::forReceivingDock(
        '11111111-1111-7111-8111-111111111111',
        '22222222-2222-7222-8222-222222222222',
    );

    expect($scope->isInbound())->toBeTrue()
        ->and($scope->isDispatch())->toBeFalse()
        ->and($scope->kind)->toBe(ScopeKind::Inbound)
        ->and($scope->warehouseId)->toBe('11111111-1111-7111-8111-111111111111')
        ->and($scope->dockId)->toBe('22222222-2222-7222-8222-222222222222')
        ->and($scope->nodeId)->toBeNull()
        ->and($scope->zoneId)->toBeNull()
        ->and($scope->municipalityId)->toBeNull();
});

it('allows an inbound scope with no specific dock (warehouse-wide)', function (): void {
    $scope = BucketScope::forReceivingDock('11111111-1111-7111-8111-111111111111');

    expect($scope->isInbound())->toBeTrue()
        ->and($scope->dockId)->toBeNull();
});

it('rejects an inbound scope without a warehouse', function (): void {
    expect(fn () => BucketScope::forReceivingDock('  '))
        ->toThrow(CapacityException::class);
});

it('reconstructs an inbound scope from columns using the scope kind', function (): void {
    $scope = BucketScope::fromColumns(
        'inbound',
        null,
        null,
        null,
        '11111111-1111-7111-8111-111111111111',
        '22222222-2222-7222-8222-222222222222',
    );

    expect($scope->isInbound())->toBeTrue()
        ->and($scope->warehouseId)->toBe('11111111-1111-7111-8111-111111111111')
        ->and($scope->dockId)->toBe('22222222-2222-7222-8222-222222222222');
});

it('reconstructs a dispatch scope from columns when scope kind is null (legacy rows)', function (): void {
    $scope = BucketScope::fromColumns(
        null,
        '33333333-3333-7333-8333-333333333333',
        null,
        null,
        null,
        null,
    );

    expect($scope->isDispatch())->toBeTrue()
        ->and($scope->isNode())->toBeTrue()
        ->and($scope->nodeId)->toBe('33333333-3333-7333-8333-333333333333');
});

it('separates inbound and dispatch scopes in equality even on the same ids', function (): void {
    $inbound = BucketScope::forReceivingDock('11111111-1111-7111-8111-111111111111');
    $dispatch = BucketScope::forNode('11111111-1111-7111-8111-111111111111');

    expect($inbound->equals($dispatch))->toBeFalse();
});
