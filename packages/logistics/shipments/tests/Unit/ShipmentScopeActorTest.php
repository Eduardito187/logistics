<?php

declare(strict_types=1);

use Logistics\Shipments\Application\Scope\ShipmentScopeActor;
use Logistics\Shipments\Domain\Service\OriginNodeSelector;
use Logistics\Shipments\Domain\ValueObject\DepartmentCode;

/**
 * Piezas puras del alcance por rol: el código de departamento, el actor que se arma desde la identidad del
 * usuario y la elección del centro de distribución de origen.
 */
it('lleva una ciudad o un alias a su código de departamento', function (): void {
    expect(DepartmentCode::fromCity('El Alto'))->toBe('la_paz')
        ->and(DepartmentCode::fromCity('Santa Cruz de la Sierra'))->toBe('santa_cruz')
        ->and(DepartmentCode::fromCity('Potosí'))->toBe('potosi')
        ->and(DepartmentCode::fromCity('Sucre'))->toBe('chuquisaca')
        ->and(DepartmentCode::fromCity('la_paz'))->toBe('la_paz')
        ->and(DepartmentCode::fromCity('Ciudad Desconocida'))->toBeNull()
        ->and(DepartmentCode::fromCity(null))->toBeNull()
        ->and(DepartmentCode::of('  La Paz '))->toBe('la_paz')
        ->and(DepartmentCode::of('Zona Franca'))->toBe('zona_franca')
        ->and(DepartmentCode::of(''))->toBeNull();
});

it('supervisor, admin y una cuenta sin rol no quedan restringidos', function (): void {
    expect(ShipmentScopeActor::fromIdentity(['admin'], city: 'la_paz')->unrestricted)->toBeTrue()
        ->and(ShipmentScopeActor::fromIdentity(['supervisor'])->unrestricted)->toBeTrue()
        ->and(ShipmentScopeActor::fromIdentity(['preparador', 'supervisor'], city: 'la_paz')->unrestricted)->toBeTrue()
        ->and(ShipmentScopeActor::fromIdentity([])->unrestricted)->toBeTrue();
});

it('el preparador queda acotado a su ciudad, y sin ciudad no queda restringido', function (): void {
    $picker = ShipmentScopeActor::fromIdentity(['preparador'], city: 'La Paz');

    expect($picker->unrestricted)->toBeFalse()
        ->and($picker->department)->toBe('la_paz')
        ->and($picker->denialCode)->toBe('out_of_scope_city')
        ->and($picker->seesNothing())->toBeFalse()
        ->and(ShipmentScopeActor::fromIdentity(['preparador'])->unrestricted)->toBeTrue()
        ->and(ShipmentScopeActor::fromIdentity(['preparador'], city: '  ')->unrestricted)->toBeTrue();
});

it('la tienda queda acotada a su nodo, y sin nodo no ve nada', function (): void {
    $node = '019a0000-0000-7000-8000-000000000001';
    $store = ShipmentScopeActor::fromIdentity(['tienda'], city: 'la_paz', nodeId: $node);

    expect($store->storeNodeId)->toBe($node)
        // La ciudad solo restringe al preparador.
        ->and($store->department)->toBeNull()
        ->and($store->denialCode)->toBe('out_of_scope_node')
        ->and(ShipmentScopeActor::fromIdentity(['tienda'])->seesNothing())->toBeTrue()
        // Un valor que no es UUID nunca llega a compararse contra una columna uuid.
        ->and(ShipmentScopeActor::fromIdentity(['tienda'], nodeId: 'no-es-uuid')->seesNothing())->toBeTrue();
});

it('chofer y transportista quedan acotados a su vínculo; sin vínculo solo en modo estricto', function (): void {
    $driverId = '019a0000-0000-7000-8000-000000000002';
    $driver = ShipmentScopeActor::fromIdentity(['chofer'], driverId: $driverId);

    expect($driver->unrestricted)->toBeFalse()
        ->and($driver->driverId)->toBe($driverId)
        ->and($driver->denialCode)->toBe('out_of_scope_assignment')
        // El vínculo solo cuenta con el rol que lo usa.
        ->and(ShipmentScopeActor::fromIdentity(['tienda'], driverId: $driverId)->driverId)->toBeNull()
        ->and(ShipmentScopeActor::fromIdentity(['chofer'])->unrestricted)->toBeTrue()
        ->and(ShipmentScopeActor::fromIdentity(['transportista'])->unrestricted)->toBeTrue()
        ->and(ShipmentScopeActor::fromIdentity(['chofer'], strictUnlinked: true)->seesNothing())->toBeTrue()
        ->and(ShipmentScopeActor::fromIdentity(['transportista'], strictUnlinked: true)->seesNothing())->toBeTrue();
});

it('elige el centro de distribución de origen: departamento destino, cercanía, prefijo y por defecto', function (): void {
    $selector = new OriginNodeSelector;
    $centers = [
        ['id' => 'a', 'code' => 'CD-LPZ', 'department' => 'la_paz', 'lat' => -16.5, 'lng' => -68.15],
        ['id' => 'b', 'code' => 'CD-SCZ', 'department' => 'santa_cruz', 'lat' => -17.78, 'lng' => -63.18],
    ];
    $prefixes = ['SCZ' => 'santa_cruz', 'LP' => 'la_paz'];

    expect($selector->select($centers, 'la_paz', -17.78, -63.18, 'SCZ-1', $prefixes, 'CD-SCZ')['id'] ?? null)->toBe('a')
        ->and($selector->select($centers, null, -17.8, -63.2, 'LP-1', $prefixes, 'CD-LPZ')['id'] ?? null)->toBe('b')
        ->and($selector->select($centers, null, null, null, 'lp-778', $prefixes, 'CD-SCZ')['id'] ?? null)->toBe('a')
        ->and($selector->select($centers, 'tarija', null, null, 'X-1', $prefixes, 'CD-SCZ')['id'] ?? null)->toBe('b')
        ->and($selector->select($centers, null, null, null, null, $prefixes, 'NO-EXISTE'))->toBeNull()
        ->and($selector->select([], 'la_paz', null, null, null, $prefixes, 'CD-SCZ'))->toBeNull();
});
