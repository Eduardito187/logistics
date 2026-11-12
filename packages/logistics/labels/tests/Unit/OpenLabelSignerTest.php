<?php

declare(strict_types=1);

use Logistics\Labels\Infrastructure\Crypto\OpenLabelSigner;
use Logistics\Labels\Tests\Support\LabelFixtures;

/**
 * Firmante ABIERTO: genera etiquetas SIN clave (key_id 0, firma marcador en cero). Su validez la da el
 * registro (label_id), no la firma. Así una etiqueta impresa nunca se descarta por un problema de clave.
 */
it('emite una etiqueta abierta con key_id 0 y sin firma secreta', function (): void {
    $signer = new OpenLabelSigner;
    $signed = $signer->sign(LabelFixtures::payload(0));

    expect($signer->activeKeyId())->toBe(0)
        ->and($signed->payload->keyId)->toBe(0)
        ->and($signed->signature)->toBe(str_repeat("\0", 64))
        // El QR sigue siendo un JSON abierto con el id y los datos del pedido/producto.
        ->and($signed->toQrContent())->toContain('"k":0')
        ->and($signed->toQrContent())->toContain('"id":');
});
