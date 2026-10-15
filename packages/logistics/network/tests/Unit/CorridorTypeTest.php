<?php

declare(strict_types=1);

use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\NodeType;

it('defines exactly the six corridors', function (): void {
    expect(CorridorType::cases())->toHaveCount(6);
});

it('keeps stable string values (contract with persistence and the app)', function (): void {
    expect(CorridorType::CdToCustomer->value)->toBe('cd_to_customer')
        ->and(CorridorType::CdToCd->value)->toBe('cd_to_cd')
        ->and(CorridorType::CdToStore->value)->toBe('cd_to_store')
        ->and(CorridorType::StoreToStore->value)->toBe('store_to_store')
        ->and(CorridorType::StoreToCd->value)->toBe('store_to_cd')
        ->and(CorridorType::StorePickup->value)->toBe('store_pickup');
});

it('derives the origin node type', function (): void {
    expect(CorridorType::CdToCustomer->originNodeType())->toBe(NodeType::DistributionCenter)
        ->and(CorridorType::CdToCd->originNodeType())->toBe(NodeType::DistributionCenter)
        ->and(CorridorType::CdToStore->originNodeType())->toBe(NodeType::DistributionCenter)
        ->and(CorridorType::StoreToStore->originNodeType())->toBe(NodeType::Store)
        ->and(CorridorType::StoreToCd->originNodeType())->toBe(NodeType::Store)
        ->and(CorridorType::StorePickup->originNodeType())->toBe(NodeType::Store);
});

it('knows which corridors deliver to another node', function (): void {
    expect(CorridorType::CdToCustomer->deliversToNode())->toBeFalse()
        ->and(CorridorType::StorePickup->deliversToNode())->toBeFalse()
        ->and(CorridorType::CdToCd->deliversToNode())->toBeTrue()
        ->and(CorridorType::CdToStore->deliversToNode())->toBeTrue()
        ->and(CorridorType::StoreToStore->deliversToNode())->toBeTrue()
        ->and(CorridorType::StoreToCd->deliversToNode())->toBeTrue();
});

it('derives the destination node type (null for terminal corridors)', function (): void {
    expect(CorridorType::CdToCustomer->destinationNodeType())->toBeNull()
        ->and(CorridorType::StorePickup->destinationNodeType())->toBeNull()
        ->and(CorridorType::CdToCd->destinationNodeType())->toBe(NodeType::DistributionCenter)
        ->and(CorridorType::StoreToCd->destinationNodeType())->toBe(NodeType::DistributionCenter)
        ->and(CorridorType::CdToStore->destinationNodeType())->toBe(NodeType::Store)
        ->and(CorridorType::StoreToStore->destinationNodeType())->toBe(NodeType::Store);
});

it('flags terminal corridors (end at the customer, not a node)', function (): void {
    expect(CorridorType::CdToCustomer->isTerminal())->toBeTrue()
        ->and(CorridorType::StorePickup->isTerminal())->toBeTrue()
        ->and(CorridorType::CdToCd->isTerminal())->toBeFalse()
        ->and(CorridorType::CdToCustomer->connectsNodes())->toBeFalse()
        ->and(CorridorType::CdToStore->connectsNodes())->toBeTrue();
});

it('accepts endpoints only when both node types match an inter-node corridor', function (): void {
    expect(CorridorType::CdToStore->acceptsEndpoints(NodeType::DistributionCenter, NodeType::Store))->toBeTrue()
        ->and(CorridorType::CdToStore->acceptsEndpoints(NodeType::Store, NodeType::Store))->toBeFalse()
        ->and(CorridorType::CdToStore->acceptsEndpoints(NodeType::DistributionCenter, NodeType::DistributionCenter))->toBeFalse()
        // Un corredor terminal nunca acepta extremos: no une dos nodos.
        ->and(CorridorType::CdToCustomer->acceptsEndpoints(NodeType::DistributionCenter, NodeType::PickupPoint))->toBeFalse();
});
