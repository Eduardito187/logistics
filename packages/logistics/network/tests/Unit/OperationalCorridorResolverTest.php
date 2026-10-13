<?php

declare(strict_types=1);

use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\Node;
use Logistics\Network\Domain\Service\OperationalCorridorResolver;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\GeoPoint;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use Logistics\Network\Domain\ValueObject\NodeType;

/**
 * Arma un nodo ya persistido (rehidratado) para los tests del resolver.
 */
function networkNode(
    NodeId $id,
    string $code,
    NodeType $type,
    ?Department $department,
    NodeStatus $status = NodeStatus::Active,
): Node {
    return Node::fromState(
        $id,
        NodeCode::fromString($code),
        $code,
        $type,
        GeoPoint::of(-16.5, -68.15),
        $status,
        $department,
    );
}

it('derives the terminal corridor each active node offers in its department', function (): void {
    $cd = networkNode(NodeId::generate(), 'CD-LP-01', NodeType::DistributionCenter, Department::LaPaz);
    $store = networkNode(NodeId::generate(), 'T-LP-01', NodeType::Store, Department::LaPaz);
    $pickup = networkNode(NodeId::generate(), 'PK-LP-01', NodeType::PickupPoint, Department::LaPaz);

    $corridors = (new OperationalCorridorResolver)->inDepartment([$cd, $store, $pickup], [], Department::LaPaz);

    // El CD ofrece última milla, la tienda ofrece recojo; el punto de recojo no ofrece corredor terminal.
    expect($corridors)->toHaveCount(2)
        ->and($corridors[0]->corridor)->toBe(CorridorType::CdToCustomer)
        ->and($corridors[0]->origin->code()->toString())->toBe('CD-LP-01')
        ->and($corridors[0]->destination)->toBeNull()
        ->and($corridors[1]->corridor)->toBe(CorridorType::StorePickup)
        ->and($corridors[1]->origin->code()->toString())->toBe('T-LP-01');
});

it('includes inter-node legs whose origin is in the department and both ends active', function (): void {
    $cd = networkNode(NodeId::generate(), 'CD-LP-01', NodeType::DistributionCenter, Department::LaPaz);
    $store = networkNode(NodeId::generate(), 'T-LP-01', NodeType::Store, Department::LaPaz);

    $leg = Leg::fromState(LegId::generate(), $cd->id(), $store->id(), CorridorType::CdToStore);

    $corridors = (new OperationalCorridorResolver)->inDepartment([$cd, $store], [$leg], Department::LaPaz);

    $interNode = array_values(array_filter(
        $corridors,
        static fn ($c): bool => $c->corridor === CorridorType::CdToStore,
    ));

    expect($interNode)->toHaveCount(1)
        ->and($interNode[0]->origin->code()->toString())->toBe('CD-LP-01')
        ->and($interNode[0]->destination?->code()->toString())->toBe('T-LP-01');
});

it('excludes legs from another department and legs touching an inactive node', function (): void {
    $cdLp = networkNode(NodeId::generate(), 'CD-LP-01', NodeType::DistributionCenter, Department::LaPaz);
    $cdScz = networkNode(NodeId::generate(), 'CD-SCZ-01', NodeType::DistributionCenter, Department::SantaCruz);
    $storeInactive = networkNode(NodeId::generate(), 'T-LP-09', NodeType::Store, Department::LaPaz, NodeStatus::Inactive);

    // Tramo con origen en Santa Cruz: no debe aparecer al consultar La Paz.
    $legScz = Leg::fromState(LegId::generate(), $cdScz->id(), $cdLp->id(), CorridorType::CdToCd);
    // Tramo con destino inactivo: no es operativo.
    $legInactive = Leg::fromState(LegId::generate(), $cdLp->id(), $storeInactive->id(), CorridorType::CdToStore);

    $corridors = (new OperationalCorridorResolver)->inDepartment(
        [$cdLp, $cdScz, $storeInactive],
        [$legScz, $legInactive],
        Department::LaPaz,
    );

    // Solo queda el corredor terminal del CD de La Paz (ningún tramo operativo).
    expect($corridors)->toHaveCount(1)
        ->and($corridors[0]->corridor)->toBe(CorridorType::CdToCustomer);
});

it('skips nodes without a department', function (): void {
    $cd = networkNode(NodeId::generate(), 'CD-XX-01', NodeType::DistributionCenter, null);

    $corridors = (new OperationalCorridorResolver)->inDepartment([$cd], [], Department::LaPaz);

    expect($corridors)->toBeEmpty();
});
