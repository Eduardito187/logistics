<?php

declare(strict_types=1);

use Logistics\Labels\Application\ReadModel\EmittedLabelView;
use Logistics\Labels\Domain\Model\EmittedLabel;
use Logistics\Labels\Tests\Support\LabelFixtures;

/**
 * El modelo de lectura aplana una etiqueta emitida a primitivos listos para la API/app: el qr_content
 * es el JSON firmado exacto (lo que escanea la app) y el zpl lo que va a la impresora.
 */
it('maps a domain label into a flat primitive view', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());
    $label = EmittedLabel::of('DM-260012345', $signed, '^XA^XZ', 1759312800);

    $view = EmittedLabelView::fromEmittedLabel($label);

    expect($view->orderNumber)->toBe('DM-260012345')
        ->and($view->labelId)->toBe('01JB8ZQ4X7K2M9N5P6R7S8T9VW')
        ->and($view->keyId)->toBe(1)
        ->and($view->sku)->toBe('LG-GT32WPP')
        ->and($view->lineNumber)->toBe(3)
        ->and($view->qrContent)->toBe($signed->toQrContent())
        ->and($view->zpl)->toBe('^XA^XZ')
        ->and($view->emittedAt)->toBe(1759312800)
        ->and($view->revoked)->toBeFalse()
        ->and($view->revokedAt)->toBeNull()
        ->and($view->revokedReason)->toBeNull();
});

it('flags a revoked label in its array shape', function (): void {
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());
    $label = EmittedLabel::of('DM-260012345', $signed, '^XA^XZ', 1759312800, 1759316400, 'reprinted');

    $array = EmittedLabelView::fromEmittedLabel($label)->toArray();

    expect(array_keys($array))->toBe([
        'order_number', 'label_id', 'key_id', 'sku', 'line_number',
        'qr_content', 'zpl', 'emitted_at', 'revoked', 'revoked_at', 'revoked_reason',
    ])
        ->and($array['revoked'])->toBeTrue()
        ->and($array['revoked_at'])->toBe(1759316400)
        ->and($array['revoked_reason'])->toBe('reprinted');
});
