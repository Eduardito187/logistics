<?php

declare(strict_types=1);

use Logistics\Network\Domain\ValueObject\NodeType;

it('reconoce el almacen de transportadora como un tipo de nodo', function (): void {
    expect(NodeType::tryFromLoose('carrier_hub'))->toBe(NodeType::CarrierHub)
        ->and(NodeType::CarrierHub->value)->toBe('carrier_hub')
        ->and(NodeType::CarrierHub->label())->toBe('Carrier hub');
});

it('el almacen de transportadora recibe carga como un hub', function (): void {
    expect(NodeType::CarrierHub->receivesInbound())->toBeTrue()
        ->and(NodeType::CentralWarehouse->receivesInbound())->toBeTrue()
        ->and(NodeType::DistributionCenter->receivesInbound())->toBeFalse()
        ->and(NodeType::Store->receivesInbound())->toBeFalse()
        ->and(NodeType::PickupPoint->receivesInbound())->toBeFalse();
});

it('da etiqueta en ingles para todos los tipos', function (): void {
    foreach (NodeType::cases() as $type) {
        expect($type->label())->toBeString()->not->toBe('');
    }
});
