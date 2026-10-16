<?php

declare(strict_types=1);

use Logistics\Labels\Domain\ValueObject\Fraction;
use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Infrastructure\Zpl\ZplLabelRenderer;
use Logistics\Labels\Tests\Support\LabelFixtures;

it('wraps the label in a ZPL format block with a QR code', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer)->render($signed);

    expect($zpl)->toStartWith('^XA')
        ->and($zpl)->toContain('^XZ')
        ->and($zpl)->toContain('^BQN,2,6')
        // El QR lleva el JSON firmado exacto (lo que la app escanea y verifica).
        ->and($zpl)->toContain('^FDMA,'.$signed->toQrContent().'^FS');
});

it('prints both a signed QR and a Code128 barcode by default', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    // El default es `both`: la etiqueta trae el QR firmado y, además, el Code128 con el id del bulto.
    $zpl = (new ZplLabelRenderer)->render($signed);

    expect($zpl)->toContain('^BQN,2,6')                                 // QR
        ->and($zpl)->toContain('^FDMA,'.$signed->toQrContent().'^FS')   // JSON firmado intacto
        ->and($zpl)->toContain('^BCN,90,Y,N,N')                         // Code128
        ->and($zpl)->toContain('^FD'.$signed->payload->id.'^FS');       // id corto del bulto
});

it('prints only the QR when the symbology is qr', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer)->render($signed, LabelSymbology::Qr);

    expect($zpl)->toContain('^BQN,2,6')
        ->and($zpl)->toContain('^FDMA,'.$signed->toQrContent().'^FS')
        ->and($zpl)->not->toContain('^BC');
});

it('prints only the Code128 with the bulk id when the symbology is barcode', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer)->render($signed, LabelSymbology::Barcode);

    expect($zpl)->toContain('^BCN,90,Y,N,N')
        ->and($zpl)->toContain('^FD'.$signed->payload->id.'^FS')
        ->and($zpl)->not->toContain('^BQ')   // sin QR
        ->and($zpl)->not->toContain('^FDMA,'); // sin el JSON firmado del QR
});

it('prints the human readable fields: sku, unit, piece, origin and destination', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer)->render($signed);

    expect($zpl)->toContain('LG-GT32WPP')   // sku
        ->and($zpl)->toContain('Unit 1/2')   // unidad N de M
        ->and($zpl)->toContain('Piece 1/1')  // pieza N de M
        ->and($zpl)->toContain('From CD-SCZ') // origen
        ->and($zpl)->toContain('To CUSTOMER'); // destino (C => cliente)
});

it('shows the node code when the destination is a node, not the customer', function (): void {
    $payload = LabelPayload::forPackage(
        keyId: 1,
        id: '01JB8ZQ4X7K2M9N5P6R7S8T9VW',
        orderNumber: 'DM-260012345',
        lineNumber: 1,
        sku: 'LG-GT32WPP',
        unit: Fraction::single(),
        piece: Fraction::single(),
        origin: 'CD-SCZ',
        destination: 'T-SCZ-03',
        issuedAt: 1759312800,
    );

    $zpl = (new ZplLabelRenderer)->render(LabelFixtures::signer()->sign($payload));

    expect($zpl)->toContain('To T-SCZ-03');
});
