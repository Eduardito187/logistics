<?php

declare(strict_types=1);

use Logistics\Labels\Domain\ValueObject\Fraction;
use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
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

it('keeps the Standard profile byte-identical to the historical layout by default', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    // Standard (default) ignora el dpi y mantiene los dots fijos del medio nativo de 4".
    $default = (new ZplLabelRenderer)->render($signed);
    $explicit = (new ZplLabelRenderer)->render($signed, LabelSymbology::Both, LabelSizeProfile::Standard, 300, 'Nombre ignorado');

    expect($default)->toBe($explicit)
        ->and($default)->toContain('^PW799')
        ->and($default)->toContain('^LL0609');
});

it('renders the Lite 50x25 profile with a bare-id QR, the product name and no barcode', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer)->render($signed, LabelSymbology::Both, LabelSizeProfile::Lite, 203, 'Galaxy A15 128GB');

    expect($zpl)->toStartWith('^XA')
        ->and($zpl)->toContain('^XZ')
        ->and($zpl)->toContain('^PW400')              // 50 mm a 203 dpi
        ->and($zpl)->toContain('^LL200')              // 25 mm a 203 dpi
        ->and($zpl)->toContain('^FDQA,'.$signed->payload->id.'^FS') // QR con el id DESNUDO (ECC Q)
        ->and($zpl)->not->toContain('^FDMA,')         // NO lleva el JSON firmado
        ->and($zpl)->not->toContain('^BC')            // NO lleva Code128
        ->and($zpl)->toContain('LG-GT32WPP')          // SKU
        ->and($zpl)->toContain('Galaxy A15 128GB')    // nombre del producto (regla «nombre + SKU»)
        ->and($zpl)->toContain('U 1/2');              // unidad N/M
});

it('falls back to the SKU as the name on Lite when the product name is missing (never empty)', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer)->render($signed, LabelSymbology::Both, LabelSizeProfile::Lite, 203, null);

    // Sin nombre, el SKU ocupa también la línea de nombre: la etiqueta nunca sale vacía.
    expect(substr_count($zpl, 'LG-GT32WPP'))->toBeGreaterThanOrEqual(2);
});

it('sanitizes ZPL control characters out of the product name', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer)->render($signed, LabelSymbology::Both, LabelSizeProfile::Lite, 203, "Combo ^XZ ~CC break");

    // El `^`/`~` del nombre no deben aparecer crudos (romperían el ZPL); se reemplazan por espacios y los
    // espacios repetidos se colapsan.
    expect($zpl)->toContain('Combo XZ CC break');
});

it('renders the Lite 40x30 profile with a Data Matrix of the bare id', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer)->render($signed, LabelSymbology::Both, LabelSizeProfile::Lite40x30, 203, 'Galaxy A15');

    expect($zpl)->toContain('^PW320')                 // 40 mm a 203 dpi
        ->and($zpl)->toContain('^LL240')              // 30 mm a 203 dpi
        ->and($zpl)->toContain('^BXN')                // Data Matrix
        ->and($zpl)->toContain('^FD'.$signed->payload->id.'^FS')
        ->and($zpl)->not->toContain('^FDMA,')
        ->and($zpl)->not->toContain('^BQ')            // sin QR
        ->and($zpl)->toContain('LG-GT32WPP')
        ->and($zpl)->toContain('Galaxy A15');
});

it('renders the Micro 30x15 profile at 300 dpi with SKU and the last 6 of the id only', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    $zpl = (new ZplLabelRenderer)->render($signed, LabelSymbology::Both, LabelSizeProfile::Micro, 300, 'Nombre que no entra');

    expect($zpl)->toContain('^PW354')                 // 30 mm a 300 dpi
        ->and($zpl)->toContain('^LL177')              // 15 mm a 300 dpi
        ->and($zpl)->toContain('^BXN')                // Data Matrix
        ->and($zpl)->toContain('...'.substr($signed->payload->id, -6)) // últimos 6 del id
        ->and($zpl)->toContain('LG-GT32WPP')          // SKU
        ->and($zpl)->not->toContain('Nombre que no entra'); // Micro no dibuja el nombre (excepción §7.4)
});

it('falls back to the Standard layout for an unknown profile value via fromValue', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    // fromValue degrada un valor desconocido a Standard; el renderer produce el layout de 4".
    $zpl = (new ZplLabelRenderer)->render($signed, LabelSymbology::Both, LabelSizeProfile::fromValue('nonsense'));

    expect($zpl)->toContain('^PW799');
});
