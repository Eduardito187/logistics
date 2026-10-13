<?php

declare(strict_types=1);

use Logistics\Labels\Domain\Exception\InvalidLabelException;
use Logistics\Labels\Domain\Service\LabelBatchFactory;
use Logistics\Labels\Domain\ValueObject\LabelPayload;

it('builds one label per physical piece: quantity x piecesPerUnit', function (): void {
    $payloads = (new LabelBatchFactory())->build(
        keyId: 1,
        orderNumber: 'DM-260012345',
        origin: 'CD-SCZ',
        destination: LabelPayload::CUSTOMER_DESTINATION,
        issuedAt: 1759312800,
        lines: [
            ['sku' => 'LG-GT32WPP', 'quantity' => 2, 'piecesPerUnit' => 3], // 6 etiquetas
            ['sku' => 'SAM-TV55', 'quantity' => 1, 'piecesPerUnit' => 1],   // 1 etiqueta
        ],
    );

    expect($payloads)->toHaveCount(7);
});

it('numbers units and pieces as N of M for each line', function (): void {
    $payloads = (new LabelBatchFactory())->build(
        keyId: 1,
        orderNumber: 'DM-260012345',
        origin: 'CD-SCZ',
        destination: LabelPayload::CUSTOMER_DESTINATION,
        issuedAt: 1759312800,
        lines: [
            ['sku' => 'LG-GT32WPP', 'quantity' => 2, 'piecesPerUnit' => 3],
        ],
    );

    // Orden: unidad 1 (piezas 1..3), luego unidad 2 (piezas 1..3).
    $fractions = array_map(
        static fn (LabelPayload $p): string => $p->unit->toCanonical().' '.$p->piece->toCanonical(),
        $payloads,
    );

    expect($fractions)->toBe([
        '1/2 1/3', '1/2 2/3', '1/2 3/3',
        '2/2 1/3', '2/2 2/3', '2/2 3/3',
    ]);
});

it('keeps the line number aligned with the line position and carries order data', function (): void {
    $payloads = (new LabelBatchFactory())->build(
        keyId: 7,
        orderNumber: 'DM-260012345',
        origin: 'CD-SCZ',
        destination: 'T-SCZ-03',
        issuedAt: 1759312800,
        lines: [
            ['sku' => 'LG-GT32WPP', 'quantity' => 1, 'piecesPerUnit' => 1], // línea 1
            ['sku' => 'SAM-TV55', 'quantity' => 1, 'piecesPerUnit' => 1],   // línea 2
        ],
    );

    expect($payloads[0]->lineNumber)->toBe(1)
        ->and($payloads[0]->sku)->toBe('LG-GT32WPP')
        ->and($payloads[1]->lineNumber)->toBe(2)
        ->and($payloads[1]->sku)->toBe('SAM-TV55')
        ->and($payloads[0]->keyId)->toBe(7)
        ->and($payloads[0]->origin)->toBe('CD-SCZ')
        ->and($payloads[0]->destination)->toBe('T-SCZ-03')
        ->and($payloads[0]->issuedAt)->toBe(1759312800);
});

it('gives every label a unique ULID id', function (): void {
    $payloads = (new LabelBatchFactory())->build(
        keyId: 1,
        orderNumber: 'DM-260012345',
        origin: 'CD-SCZ',
        destination: LabelPayload::CUSTOMER_DESTINATION,
        issuedAt: 1759312800,
        lines: [
            ['sku' => 'LG-GT32WPP', 'quantity' => 5, 'piecesPerUnit' => 2],
        ],
    );

    $ids = array_map(static fn (LabelPayload $p): string => $p->id, $payloads);

    expect($ids)->toHaveCount(10)
        ->and(array_unique($ids))->toHaveCount(10)
        ->and($ids)->each->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('rejects a line with no units or no pieces per unit', function (): void {
    $factory = new LabelBatchFactory();

    expect(fn () => $factory->build(1, 'DM-1', 'CD-SCZ', 'C', 0, [['sku' => 'SKU', 'quantity' => 0, 'piecesPerUnit' => 1]]))
        ->toThrow(InvalidLabelException::class, 'unidad')
        ->and(fn () => $factory->build(1, 'DM-1', 'CD-SCZ', 'C', 0, [['sku' => 'SKU', 'quantity' => 1, 'piecesPerUnit' => 0]]))
        ->toThrow(InvalidLabelException::class, 'pieza');
});
