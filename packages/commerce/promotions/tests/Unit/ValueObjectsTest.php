<?php

declare(strict_types=1);

use Commerce\Promotions\Domain\Exception\PromotionsException;
use Commerce\Promotions\Domain\ValueObject\PromotionConditions;

it('condiciones sin restricciones aplican a cualquier canal, fecha y subtotal', function (): void {
    $conditions = PromotionConditions::of();

    expect($conditions->channelMatches('Retail'))->toBeTrue()
        ->and($conditions->withinWindow(new DateTimeImmutable('2026-10-29T12:00:00+00:00')))->toBeTrue()
        ->and($conditions->subtotalMeetsMinimum(0))->toBeTrue();
});

it('condiciones rechazan un subtotal mínimo negativo', function (): void {
    expect(fn () => PromotionConditions::of(-1))->toThrow(PromotionsException::class);
});

it('condiciones rechazan un canal inválido', function (): void {
    expect(fn () => PromotionConditions::of(0, 'Mayorista'))->toThrow(PromotionsException::class);
});

it('condiciones rechazan una fecha ISO malformada', function (): void {
    expect(fn () => PromotionConditions::of(0, null, 'no-es-fecha'))->toThrow(PromotionsException::class);
});

it('condiciones rechazan inicio posterior al fin', function (): void {
    expect(fn () => PromotionConditions::of(0, null, '2026-12-01T00:00:00+00:00', '2026-11-01T00:00:00+00:00'))
        ->toThrow(PromotionsException::class);
});

it('condiciones ida y vuelta por forma primitiva', function (): void {
    $conditions = PromotionConditions::of(50000, 'B2B', '2026-11-01T00:00:00+00:00', null);
    $rebuilt = PromotionConditions::fromArray($conditions->toArray());

    expect($rebuilt->toArray())->toBe($conditions->toArray())
        ->and($rebuilt->minSubtotalCents)->toBe(50000)
        ->and($rebuilt->channel)->toBe('B2B');
});
