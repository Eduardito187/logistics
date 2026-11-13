<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\ValueObject\TransferKind;

/**
 * Dimensión de propiedad de un traspaso de stock a SAP (ADR 017): etiquetas y reglas de posteo. PHP puro.
 */

it('expone los dos tipos con su valor de catálogo', function (): void {
    expect(TransferKind::Owned->value)->toBe('owned')
        ->and(TransferKind::Consignment->value)->toBe('consignment')
        ->and(TransferKind::from('owned'))->toBe(TransferKind::Owned)
        ->and(TransferKind::from('consignment'))->toBe(TransferKind::Consignment);
});

it('da una etiqueta legible en inglés para cada tipo', function (): void {
    expect(TransferKind::Owned->label())->toBe('Owned (retail)')
        ->and(TransferKind::Consignment->label())->toBe('Consignment');
});

it('solo el stock propio postea su titularidad a SAP (regla dura del roadmap)', function (): void {
    expect(TransferKind::Owned->postsOwnershipToSap())->toBeTrue()
        ->and(TransferKind::Consignment->postsOwnershipToSap())->toBeFalse();
});

it('solo la consignación exige un seller dueño', function (): void {
    expect(TransferKind::Consignment->requiresOwner())->toBeTrue()
        ->and(TransferKind::Owned->requiresOwner())->toBeFalse();
});
