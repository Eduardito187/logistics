<?php

declare(strict_types=1);

use Logistics\Labels\Domain\Model\EmittedLabel;
use Logistics\Labels\Tests\Support\LabelFixtures;

/**
 * El modelo de dominio EmittedLabel expone la identidad de negocio (el id del payload) y datos
 * derivados de la etiqueta firmada, sin conocer la fila de almacenamiento.
 */
function emittedLabel(): EmittedLabel
{
    $signed = LabelFixtures::signer()->sign(LabelFixtures::payload());

    return EmittedLabel::of('DM-260012345', $signed, '^XA^XZ', 1759312800);
}

it('exposes the business identity and signed fields of the label', function (): void {
    $label = emittedLabel();

    expect($label->labelId())->toBe('01JB8ZQ4X7K2M9N5P6R7S8T9VW')
        ->and($label->keyId())->toBe(1)
        ->and($label->sku())->toBe('LG-GT32WPP')
        ->and($label->lineNumber())->toBe(3)
        ->and($label->orderNumber)->toBe('DM-260012345')
        ->and($label->signedJson())->toBe($label->label->toQrContent());
});
