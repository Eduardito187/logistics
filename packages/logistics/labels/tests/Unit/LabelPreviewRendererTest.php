<?php

declare(strict_types=1);

use Logistics\Labels\Presentation\Support\LabelPreviewRenderer;

// Renderizado on-premise (CERO CDN) de las simbologías de la etiqueta como SVG inline: el QR firmado
// (escaneable, mismo contenido que imprime la Zebra) y el Code128 del id del bulto.

beforeEach(function (): void {
    $this->renderer = new LabelPreviewRenderer;
});

it('renderiza el QR del contenido firmado como SVG inline', function (): void {
    $svg = $this->renderer->qr('{"v":1,"id":"01M6PAYD9GZJ","s":"abc"}');

    expect($svg)->toStartWith('<svg')
        ->and($svg)->not->toContain('<?xml');
});

it('renderiza el Code128 del id del bulto como SVG inline', function (): void {
    $svg = $this->renderer->barcode('01M6PAYD9GZJV1D39MVCAVW2WN');

    expect($svg)->toStartWith('<svg');
});

it('degrada a cadena vacía con entrada vacía (tolerante)', function (): void {
    expect($this->renderer->qr(''))->toBe('')
        ->and($this->renderer->barcode('  '))->toBe('');
});
