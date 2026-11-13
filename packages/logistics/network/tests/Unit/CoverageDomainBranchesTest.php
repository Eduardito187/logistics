<?php

declare(strict_types=1);

use Logistics\Network\Application\ReadModel\OperationalCorridorView;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ReceivingDock;
use Logistics\Network\Domain\ValueObject\DockCapacity;
use Logistics\Network\Domain\ValueObject\DockCode;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\ReceivingDockId;

/**
 * Cobertura de ramas sueltas del dominio y los modelos de lectura del contexto network que los tests
 * existentes no ejercitan: longitud máxima y comparación de códigos, mutadores del andén y el toArray
 * del corredor operativo. Todo PHP puro, sin base de datos.
 */
it('rejects a dock code longer than twenty characters', function (): void {
    expect(fn () => DockCode::fromString('ABCDEFGHIJ-KLMNOPQRST'))
        ->toThrow(NetworkException::class, 'fuera de longitud');
});

it('compares dock codes by value and renders them as text', function (): void {
    $code = DockCode::fromString('dock-01');

    expect($code->equals(DockCode::fromString('DOCK-01')))->toBeTrue()
        ->and($code->equals(DockCode::fromString('DOCK-02')))->toBeFalse()
        ->and((string) $code)->toBe('DOCK-01');
});

it('compares dock capacities by value', function (): void {
    expect(DockCapacity::of(4)->equals(DockCapacity::of(4)))->toBeTrue()
        ->and(DockCapacity::of(4)->equals(DockCapacity::of(5)))->toBeFalse();
});

it('renders a node code as text', function (): void {
    expect((string) NodeCode::fromString('cd-lp-01'))->toBe('CD-LP-01');
});

it('activates, deactivates and rechanges the capacity of a dock', function (): void {
    $dock = ReceivingDock::fromState(
        ReceivingDockId::generate(),
        NodeId::generate(),
        DockCode::fromString('A1'),
        'Dock A1',
        DockCapacity::of(2),
        true,
    );

    $dock->deactivate();
    expect($dock->isActive())->toBeFalse();

    $dock->activate();
    expect($dock->isActive())->toBeTrue();

    $dock->changeCapacity(DockCapacity::of(7));
    expect($dock->capacity()->appointmentsPerSlot)->toBe(7);
});

it('flattens an inter-node operational corridor to a primitive array', function (): void {
    $view = new OperationalCorridorView(
        corridorType: 'cd_to_store',
        department: 'la_paz',
        originNodeId: '01920000-0000-7000-8000-000000000001',
        originNodeCode: 'CD-LP-01',
        originNodeName: 'CD La Paz',
        originNodeType: 'distribution_center',
        destinationNodeId: '01920000-0000-7000-8000-000000000002',
        destinationNodeCode: 'T-LP-01',
        destinationNodeName: 'Tienda La Paz',
        destinationNodeType: 'store',
    );

    expect($view->toArray())->toBe([
        'corridor_type' => 'cd_to_store',
        'department' => 'la_paz',
        'origin_node_id' => '01920000-0000-7000-8000-000000000001',
        'origin_node_code' => 'CD-LP-01',
        'origin_node_name' => 'CD La Paz',
        'origin_node_type' => 'distribution_center',
        'destination_node_id' => '01920000-0000-7000-8000-000000000002',
        'destination_node_code' => 'T-LP-01',
        'destination_node_name' => 'Tienda La Paz',
        'destination_node_type' => 'store',
    ]);
});

it('flattens a terminal operational corridor with a null destination', function (): void {
    $view = new OperationalCorridorView(
        corridorType: 'cd_to_customer',
        department: 'santa_cruz',
        originNodeId: '01920000-0000-7000-8000-000000000003',
        originNodeCode: 'CD-SCZ-01',
        originNodeName: 'CD Santa Cruz',
        originNodeType: 'distribution_center',
        destinationNodeId: null,
        destinationNodeCode: null,
        destinationNodeName: null,
        destinationNodeType: null,
    );

    $array = $view->toArray();

    expect($array['destination_node_id'])->toBeNull()
        ->and($array['destination_node_code'])->toBeNull()
        ->and($array['destination_node_name'])->toBeNull()
        ->and($array['destination_node_type'])->toBeNull()
        ->and($array['origin_node_code'])->toBe('CD-SCZ-01');
});
