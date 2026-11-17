<?php

declare(strict_types=1);

use Logistics\Network\Application\Contract\NetworkNodeDirectory;
use Logistics\Network\Application\Service\RoutedItineraryPlanner;

/**
 * Directorio de nodos en memoria para los tests del ruteo: un mapa nodo → departamento, un mapa
 * departamento → hub y un carrier_hub opcional. Imita al lector real sin tocar la base.
 */
function itineraryDirectory(
    array $departmentOf = [],
    array $hubOfDepartment = [],
    ?string $carrierHub = null,
): NetworkNodeDirectory {
    return new class($departmentOf, $hubOfDepartment, $carrierHub) implements NetworkNodeDirectory
    {
        /**
         * @param array<string, string> $departmentOf     id de nodo → código de departamento
         * @param array<string, string> $hubOfDepartment  código de departamento → id de nodo hub
         */
        public function __construct(
            private array $departmentOf,
            private array $hubOfDepartment,
            private ?string $carrierHub,
        ) {}

        public function departmentOf(string $nodeId): ?string
        {
            return $this->departmentOf[$nodeId] ?? null;
        }

        public function hubOfDepartment(string $department): ?string
        {
            return $this->hubOfDepartment[$department] ?? null;
        }

        public function carrierHub(?string $carrierId): ?string
        {
            return $this->carrierHub;
        }
    };
}

it('propia mismo departamento: un solo tramo own_fleet', function (): void {
    $planner = new RoutedItineraryPlanner(itineraryDirectory(
        departmentOf: ['CD-SCZ' => 'santa_cruz'],
    ));

    $legs = $planner->planLegs('CD-SCZ', 'T-SCZ', 'santa_cruz', null, 'own_fleet', null, null);

    expect($legs)->toHaveCount(1)
        ->and($legs[0])->toBe([
            'seq' => 1,
            'from_node_id' => 'CD-SCZ',
            'to_node_id' => 'T-SCZ',
            'from_department' => 'santa_cruz',
            'to_department' => 'santa_cruz',
            'haulage_mode' => 'own_fleet',
            'carrier_id' => null,
        ]);
});

it('propia a domicilio en el mismo departamento: ultimo tramo termina en el cliente (to_node_id null)', function (): void {
    $planner = new RoutedItineraryPlanner(itineraryDirectory(
        departmentOf: ['CD-SCZ' => 'santa_cruz'],
    ));

    $legs = $planner->planLegs('CD-SCZ', null, 'santa_cruz', ['lat' => -17.78, 'lng' => -63.18], 'own_fleet', null, null);

    expect($legs)->toHaveCount(1)
        ->and($legs[0]['to_node_id'])->toBeNull()
        ->and($legs[0]['haulage_mode'])->toBe('own_fleet');
});

it('propia interdepartamental: dos tramos own_fleet, el primero termina en el hub del departamento destino', function (): void {
    $planner = new RoutedItineraryPlanner(itineraryDirectory(
        departmentOf: ['CD-SCZ' => 'santa_cruz', 'CD-LP' => 'la_paz'],
        hubOfDepartment: ['la_paz' => 'CD-LP'],
    ));

    $legs = $planner->planLegs('CD-SCZ', 'T-LP', 'la_paz', null, 'own_fleet', null, null);

    expect($legs)->toHaveCount(2)
        ->and($legs[0])->toBe([
            'seq' => 1,
            'from_node_id' => 'CD-SCZ',
            'to_node_id' => 'CD-LP',
            'from_department' => 'santa_cruz',
            'to_department' => 'la_paz',
            'haulage_mode' => 'own_fleet',
            'carrier_id' => null,
        ])
        ->and($legs[1])->toBe([
            'seq' => 2,
            'from_node_id' => 'CD-LP',
            'to_node_id' => 'T-LP',
            'from_department' => 'la_paz',
            'to_department' => 'la_paz',
            'haulage_mode' => 'own_fleet',
            'carrier_id' => null,
        ]);
});

it('propia interdepartamental sin hub sembrado: degrada a un tramo directo', function (): void {
    $planner = new RoutedItineraryPlanner(itineraryDirectory(
        departmentOf: ['CD-SCZ' => 'santa_cruz'],
    ));

    $legs = $planner->planLegs('CD-SCZ', 'T-LP', 'la_paz', null, 'own_fleet', null, null);

    expect($legs)->toHaveCount(1)
        ->and($legs[0]['from_node_id'])->toBe('CD-SCZ')
        ->and($legs[0]['to_node_id'])->toBe('T-LP')
        ->and($legs[0]['haulage_mode'])->toBe('own_fleet');
});

it('tercerizado: dos tramos, el segundo third_party con carrier_id y origen en el almacen de la transportadora', function (): void {
    $planner = new RoutedItineraryPlanner(itineraryDirectory(
        departmentOf: ['CD-SCZ' => 'santa_cruz', 'CH-SCZ' => 'santa_cruz'],
        carrierHub: 'CH-SCZ',
    ));

    $legs = $planner->planLegs('CD-SCZ', 'T-LP', 'la_paz', null, 'outsourced', 'carrier-9', null);

    expect($legs)->toHaveCount(2)
        ->and($legs[0])->toBe([
            'seq' => 1,
            'from_node_id' => 'CD-SCZ',
            'to_node_id' => 'CH-SCZ',
            'from_department' => 'santa_cruz',
            'to_department' => 'santa_cruz',
            'haulage_mode' => 'own_fleet',
            'carrier_id' => null,
        ])
        ->and($legs[1])->toBe([
            'seq' => 2,
            'from_node_id' => 'CH-SCZ',
            'to_node_id' => 'T-LP',
            'from_department' => 'santa_cruz',
            'to_department' => 'la_paz',
            'haulage_mode' => 'third_party',
            'carrier_id' => 'carrier-9',
        ]);
});

it('tercerizado con almacen de transportadora explicito: respeta el carrierHubNodeId recibido', function (): void {
    $planner = new RoutedItineraryPlanner(itineraryDirectory(
        departmentOf: ['CD-SCZ' => 'santa_cruz', 'CH-X' => 'cochabamba'],
    ));

    $legs = $planner->planLegs('CD-SCZ', null, 'la_paz', ['lat' => -16.5, 'lng' => -68.1], 'outsourced', 'carrier-1', 'CH-X');

    expect($legs)->toHaveCount(2)
        ->and($legs[0]['to_node_id'])->toBe('CH-X')
        ->and($legs[1]['from_node_id'])->toBe('CH-X')
        ->and($legs[1]['to_node_id'])->toBeNull()
        ->and($legs[1]['haulage_mode'])->toBe('third_party')
        ->and($legs[1]['carrier_id'])->toBe('carrier-1');
});

it('tercerizado sin almacen de transportadora: degrada a un tramo third_party directo', function (): void {
    $planner = new RoutedItineraryPlanner(itineraryDirectory(
        departmentOf: ['CD-SCZ' => 'santa_cruz'],
    ));

    $legs = $planner->planLegs('CD-SCZ', 'T-LP', 'la_paz', null, 'outsourced', 'carrier-7', null);

    expect($legs)->toHaveCount(1)
        ->and($legs[0]['from_node_id'])->toBe('CD-SCZ')
        ->and($legs[0]['to_node_id'])->toBe('T-LP')
        ->and($legs[0]['haulage_mode'])->toBe('third_party')
        ->and($legs[0]['carrier_id'])->toBe('carrier-7');
});
