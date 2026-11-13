<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\ValueObject\ReceivedQuantity;

it('compara dos cantidades por su desglose completo', function (): void {
    $quantity = ReceivedQuantity::of(9, 1, 2);

    expect($quantity->equals(ReceivedQuantity::of(9, 1, 2)))->toBeTrue()
        ->and($quantity->equals(ReceivedQuantity::of(9, 1, 3)))->toBeFalse()
        ->and($quantity->equals(ReceivedQuantity::of(8, 1, 2)))->toBeFalse()
        ->and($quantity->equals(ReceivedQuantity::of(9, 0, 2)))->toBeFalse();
});

it('serializa el desglose aceptado/rechazado/dañado a primitivos', function (): void {
    expect(ReceivedQuantity::of(5, 1, 2)->toArray())->toBe([
        'accepted' => 5,
        'rejected' => 1,
        'damaged' => 2,
    ]);
});

it('expone cada componente del desglose', function (): void {
    $quantity = ReceivedQuantity::of(5, 1, 2);

    expect($quantity->accepted)->toBe(5)
        ->and($quantity->rejected)->toBe(1)
        ->and($quantity->damaged)->toBe(2);
});
