<?php

declare(strict_types=1);

use Commerce\Promotions\Domain\Event\PromotionActivated;
use Commerce\Promotions\Domain\Event\PromotionCreated;
use Commerce\Promotions\Domain\Event\PromotionExpired;
use Commerce\Promotions\Domain\Event\PromotionRulesChanged;
use Commerce\Promotions\Domain\Exception\PromotionsException;
use Commerce\Promotions\Domain\Model\Promotion;
use Commerce\Promotions\Domain\ValueObject\PromotionConditions;
use Commerce\Promotions\Domain\ValueObject\PromotionId;
use Commerce\Promotions\Domain\ValueObject\PromotionStatus;
use Commerce\Promotions\Domain\ValueObject\PromotionType;

// Instante de referencia para los casos de discountFor. Nombre único: las consts top-level son
// globales dentro del único proceso de Pest y no deben colisionar con otros archivos de test.
const PROMOTION_TEST_AT = '2026-10-29T12:00:00+00:00';

function promotionsAt(string $iso): DateTimeImmutable
{
    return new DateTimeImmutable($iso);
}

function promotionsCreate(
    PromotionType $type,
    int $value,
    ?string $code = null,
    ?PromotionConditions $conditions = null,
): Promotion {
    return Promotion::create(
        PromotionId::generate(),
        'Promo de prueba',
        $code,
        $type,
        $value,
        'BOB',
        $conditions ?? PromotionConditions::of(),
    );
}

it('create deja la promoción en borrador v1 y graba PromotionCreated', function (): void {
    $promotion = promotionsCreate(PromotionType::PercentageOff, 1000, 'BIENVENIDA');

    expect($promotion->status())->toBe(PromotionStatus::Draft)
        ->and($promotion->version())->toBe(1)
        ->and($promotion->isCoupon())->toBeTrue()
        ->and($promotion->code())->toBe('BIENVENIDA');

    $events = $promotion->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PromotionCreated::class);
});

it('normaliza el código del cupón a mayúsculas sin espacios', function (): void {
    $promotion = promotionsCreate(PromotionType::PercentageOff, 500, '  welcome  ');

    expect($promotion->code())->toBe('WELCOME');
});

it('sin código es una promoción automática (no cupón)', function (): void {
    $promotion = promotionsCreate(PromotionType::FreeShipping, 0);

    expect($promotion->isCoupon())->toBeFalse()
        ->and($promotion->code())->toBeNull();
});

it('rechaza un código vacío', function (): void {
    expect(fn () => promotionsCreate(PromotionType::PercentageOff, 500, '   '))
        ->toThrow(PromotionsException::class);
});

it('rechaza un porcentaje fuera de 0..10000 basis points', function (): void {
    expect(fn () => promotionsCreate(PromotionType::PercentageOff, 10001))
        ->toThrow(PromotionsException::class);
    expect(fn () => promotionsCreate(PromotionType::PercentageOff, -1))
        ->toThrow(PromotionsException::class);
});

it('rechaza un envío gratis con valor distinto de 0', function (): void {
    expect(fn () => promotionsCreate(PromotionType::FreeShipping, 100))
        ->toThrow(PromotionsException::class);
});

it('activate y expire son idempotentes y graban su evento', function (): void {
    $promotion = promotionsCreate(PromotionType::PercentageOff, 1000);
    $promotion->releaseEvents();

    $promotion->activate();
    expect($promotion->status())->toBe(PromotionStatus::Active);
    $events = $promotion->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PromotionActivated::class);
    $promotion->activate();
    expect($promotion->releaseEvents())->toHaveCount(0);

    $promotion->expire();
    expect($promotion->status())->toBe(PromotionStatus::Expired);
    $events = $promotion->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PromotionExpired::class);
    $promotion->expire();
    expect($promotion->releaseEvents())->toHaveCount(0);
});

it('changeRules incrementa la versión y graba PromotionRulesChanged', function (): void {
    $promotion = promotionsCreate(PromotionType::PercentageOff, 1000);
    $promotion->releaseEvents();

    $promotion->changeRules(PromotionType::FixedAmountOff, 5000, PromotionConditions::of());

    expect($promotion->version())->toBe(2)
        ->and($promotion->type())->toBe(PromotionType::FixedAmountOff)
        ->and($promotion->value())->toBe(5000);

    $events = $promotion->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PromotionRulesChanged::class)
        ->and($events[0]->payload()['version'])->toBe(2);
});

it('changeRules valida las invariantes del nuevo tipo', function (): void {
    $promotion = promotionsCreate(PromotionType::PercentageOff, 1000);

    expect(fn () => $promotion->changeRules(PromotionType::PercentageOff, 20000, PromotionConditions::of()))
        ->toThrow(PromotionsException::class);
});

it('discountFor de 10% sobre 100000 centavos da 10000 (activa)', function (): void {
    $promotion = promotionsCreate(PromotionType::PercentageOff, 1000);
    $promotion->activate();

    expect($promotion->discountFor(100000, 'Retail', promotionsAt(PROMOTION_TEST_AT)))
        ->toBe(10000);
});

it('discountFor de monto fijo se topa al subtotal', function (): void {
    $promotion = promotionsCreate(PromotionType::FixedAmountOff, 150000);
    $promotion->activate();

    // Monto fijo 1500.00 Bs pero el subtotal es 1000.00 Bs → se topa a 100000.
    expect($promotion->discountFor(100000, 'Retail', promotionsAt(PROMOTION_TEST_AT)))
        ->toBe(100000);
});

it('discountFor es 0 si la promoción no está activa', function (): void {
    $promotion = promotionsCreate(PromotionType::PercentageOff, 1000);

    expect($promotion->discountFor(100000, 'Retail', promotionsAt(PROMOTION_TEST_AT)))
        ->toBe(0);
});

it('discountFor es 0 fuera de la ventana de fechas', function (): void {
    $promotion = promotionsCreate(
        PromotionType::PercentageOff,
        1000,
        null,
        PromotionConditions::of(0, null, '2026-11-01T00:00:00+00:00', '2026-11-30T23:59:59+00:00'),
    );
    $promotion->activate();

    // Antes del inicio.
    expect($promotion->discountFor(100000, 'Retail', promotionsAt(PROMOTION_TEST_AT)))
        ->toBe(0);
    // Dentro de la ventana.
    expect($promotion->discountFor(100000, 'Retail', promotionsAt('2026-11-10T12:00:00+00:00')))
        ->toBe(10000);
});

it('discountFor es 0 para otro canal', function (): void {
    $promotion = promotionsCreate(
        PromotionType::PercentageOff,
        1000,
        null,
        PromotionConditions::of(0, 'B2B'),
    );
    $promotion->activate();

    expect($promotion->discountFor(100000, 'Retail', promotionsAt(PROMOTION_TEST_AT)))
        ->toBe(0);
    expect($promotion->discountFor(100000, 'B2B', promotionsAt(PROMOTION_TEST_AT)))
        ->toBe(10000);
});

it('discountFor es 0 si no se alcanza el subtotal mínimo', function (): void {
    $promotion = promotionsCreate(
        PromotionType::PercentageOff,
        1000,
        null,
        PromotionConditions::of(50000),
    );
    $promotion->activate();

    expect($promotion->discountFor(40000, 'Retail', promotionsAt(PROMOTION_TEST_AT)))
        ->toBe(0);
    expect($promotion->discountFor(60000, 'Retail', promotionsAt(PROMOTION_TEST_AT)))
        ->toBe(6000);
});

it('free shipping no descuenta productos pero se marca como tal', function (): void {
    $promotion = promotionsCreate(PromotionType::FreeShipping, 0);
    $promotion->activate();

    expect($promotion->isFreeShipping())->toBeTrue()
        ->and($promotion->discountFor(100000, 'Retail', promotionsAt(PROMOTION_TEST_AT)))
        ->toBe(0);
});
