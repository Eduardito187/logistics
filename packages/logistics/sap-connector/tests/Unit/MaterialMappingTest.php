<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\MaterialMapping;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;

/**
 * Define un mapeo de ejemplo entre un sku propio y su coordenada SAP.
 */
function defineMapping(string $sku = 'LG-GT32'): MaterialMapping
{
    return MaterialMapping::define(
        MaterialMappingId::generate(),
        $sku,
        SapMaterial::of('MAT-100'),
        SapCenter::of('C001'),
        SapWarehouse::of('A01'),
    );
}

it('define un mapeo sku propio ↔ coordenada SAP', function (): void {
    $mapping = defineMapping();

    expect($mapping->sku())->toBe('LG-GT32')
        ->and($mapping->material()->value)->toBe('MAT-100')
        ->and($mapping->center()->value)->toBe('C001')
        ->and($mapping->warehouse()->value)->toBe('A01');
});

it('normaliza el sku al definir y lo usa para traducir', function (): void {
    $mapping = defineMapping('  LG-GT32  ');

    expect($mapping->sku())->toBe('LG-GT32')
        ->and($mapping->matches('LG-GT32'))->toBeTrue()
        ->and($mapping->matches('  LG-GT32  '))->toBeTrue()
        ->and($mapping->matches('OTRO'))->toBeFalse();
});

it('rechaza un sku vacío', function (): void {
    expect(fn () => defineMapping('   '))
        ->toThrow(SapConnectorException::class, 'sku del mapeo no puede estar vacío');
});

it('rechaza un material SAP vacío', function (): void {
    expect(fn () => SapMaterial::of(''))
        ->toThrow(SapConnectorException::class, 'material SAP no puede estar vacío');
});

it('compara materiales por valor', function (): void {
    expect(SapMaterial::of('MAT-1')->equals(SapMaterial::of('MAT-1')))->toBeTrue()
        ->and(SapMaterial::of('MAT-1')->equals(SapMaterial::of('MAT-2')))->toBeFalse();
});
