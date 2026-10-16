<?php

declare(strict_types=1);

use Logistics\Sellers\Domain\Event\SellerActivated;
use Logistics\Sellers\Domain\Event\SellerRegistered;
use Logistics\Sellers\Domain\Event\SellerSkuUpserted;
use Logistics\Sellers\Domain\Event\SellerSuspended;
use Logistics\Sellers\Domain\Event\SettlementTermsChanged;
use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\Model\Seller;
use Logistics\Sellers\Domain\ValueObject\ContactInfo;
use Logistics\Sellers\Domain\ValueObject\Money;
use Logistics\Sellers\Domain\ValueObject\PaymentCycle;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Domain\ValueObject\SellerSku;
use Logistics\Sellers\Domain\ValueObject\SellerStatus;
use Logistics\Sellers\Domain\ValueObject\SettlementTerms;

function sellersRegister(): Seller
{
    return Seller::register(
        SellerId::generate(),
        'Boliviana de Electrodomésticos S.R.L.',
        'NIT-5001001',
        ContactInfo::of('María Ríos', 'ventas@boleldom.bo', '70011001'),
    );
}

it('da de alta un seller en onboarding y graba el evento', function (): void {
    $seller = sellersRegister();

    expect($seller->status())->toBe(SellerStatus::Onboarding)
        ->and($seller->legalName())->toBe('Boliviana de Electrodomésticos S.R.L.')
        ->and($seller->taxId())->toBe('NIT-5001001')
        ->and($seller->settlementTerms())->toBeNull()
        ->and($seller->contact()->email)->toBe('ventas@boleldom.bo');

    $events = $seller->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(SellerRegistered::class)
        ->and($events[0]->eventName())->toBe('sellers.seller_registered');
});

it('rechaza registrar sin nombre legal o sin NIT', function (callable $build, string $reason): void {
    expect($build)->toThrow(SellersException::class, $reason);
})->with([
    'sin nombre' => [fn () => Seller::register(SellerId::generate(), '  ', 'NIT-1', ContactInfo::of('A', 'a@b.co')), 'nombre'],
    'sin NIT' => [fn () => Seller::register(SellerId::generate(), 'ACME', '  ', ContactInfo::of('A', 'a@b.co')), 'NIT'],
]);

it('se activa y se suspende grabando los cambios', function (): void {
    $seller = sellersRegister();
    $seller->releaseEvents();

    $seller->activate();
    expect($seller->status())->toBe(SellerStatus::Active);

    $seller->suspend();
    expect($seller->status())->toBe(SellerStatus::Suspended);

    $events = $seller->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(SellerActivated::class)
        ->and($events[1])->toBeInstanceOf(SellerSuspended::class);
});

it('es idempotente al activar dos veces', function (): void {
    $seller = sellersRegister();
    $seller->releaseEvents();

    $seller->activate();
    $seller->releaseEvents();
    $seller->activate();

    expect($seller->releaseEvents())->toBe([]);
});

it('pacta términos de liquidación y graba el evento', function (): void {
    $seller = sellersRegister();
    $seller->releaseEvents();

    $terms = SettlementTerms::of(1500, Money::of(2000), PaymentCycle::Biweekly);
    $seller->changeSettlementTerms($terms);

    expect($seller->settlementTerms())->not->toBeNull()
        ->and($seller->settlementTerms()?->commissionBasisPoints)->toBe(1500);

    $events = $seller->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(SettlementTermsChanged::class)
        ->and($events[0]->eventName())->toBe('sellers.settlement_terms_changed')
        ->and($events[0]->fixedFeeCents)->toBe(2000)
        ->and($events[0]->currency)->toBe('BOB');
});

it('da de alta un SKU y graba el evento', function (): void {
    $seller = sellersRegister();
    $seller->activate();
    $seller->releaseEvents();

    $seller->upsertSku(SellerSku::of('BEL-TV-55', 'MAT-900155'));

    $events = $seller->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(SellerSkuUpserted::class)
        ->and($events[0]->sku)->toBe('BEL-TV-55')
        ->and($events[0]->sapMaterial)->toBe('MAT-900155');
});

it('un seller suspendido no puede dar de alta SKUs', function (): void {
    $seller = sellersRegister();
    $seller->suspend();
    $seller->releaseEvents();

    expect(fn () => $seller->upsertSku(SellerSku::of('X-1')))
        ->toThrow(SellersException::class, 'suspendido');
});
