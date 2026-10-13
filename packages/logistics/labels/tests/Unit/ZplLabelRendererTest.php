<?php

declare(strict_types=1);

use Logistics\Labels\Infrastructure\Zpl\ZplLabelRenderer;
use Logistics\Labels\Tests\Support\LabelFixtures;

it('wraps the label in a ZPL format block with a QR code', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer())->render($signed);

    expect($zpl)->toStartWith('^XA')
        ->and($zpl)->toContain('^XZ')
        ->and($zpl)->toContain('^BQN,2,6')
        // El QR lleva el JSON firmado exacto (lo que la app escanea y verifica).
        ->and($zpl)->toContain('^FDMA,'.$signed->toQrContent().'^FS');
});

it('prints the human readable fields: sku, unit, piece, origin and destination', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer())->render($signed);

    expect($zpl)->toContain('LG-GT32WPP')   // sku
        ->and($zpl)->toContain('Unit 1/2')   // unidad N de M
        ->and($zpl)->toContain('Piece 1/1')  // pieza N de M
        ->and($zpl)->toContain('From CD-SCZ') // origen
        ->and($zpl)->toContain('To CUSTOMER'); // destino (C => cliente)
});

it('shows the node code when the destination is a node, not the customer', function (): void {
    $payload = \Logistics\Labels\Domain\ValueObject\LabelPayload::forPackage(
        keyId: 1,
        id: '01JB8ZQ4X7K2M9N5P6R7S8T9VW',
        orderNumber: 'DM-260012345',
        lineNumber: 1,
        sku: 'LG-GT32WPP',
        unit: \Logistics\Labels\Domain\ValueObject\Fraction::single(),
        piece: \Logistics\Labels\Domain\ValueObject\Fraction::single(),
        origin: 'CD-SCZ',
        destination: 'T-SCZ-03',
        issuedAt: 1759312800,
    );

    $zpl = (new ZplLabelRenderer())->render(LabelFixtures::signer()->sign($payload));

    expect($zpl)->toContain('To T-SCZ-03');
});
