<?php

declare(strict_types=1);

use Commerce\Pricing\Domain\Event\PriceListActivated;
use Commerce\Pricing\Domain\Event\PriceListArchived;
use Commerce\Pricing\Domain\Event\PriceListCreated;
use Commerce\Pricing\Domain\Event\PriceSet;
use Commerce\Pricing\Domain\Exception\PricingException;
use Commerce\Pricing\Domain\Model\PriceList;
use Commerce\Pricing\Domain\ValueObject\Channel;
use Commerce\Pricing\Domain\ValueObject\Money;
use Commerce\Pricing\Domain\ValueObject\PriceListId;
use Commerce\Pricing\Domain\ValueObject\PriceListStatus;
use Commerce\Pricing\Domain\ValueObject\Sku;

function pricingCreateList(Channel $channel = Channel::Retail): PriceList
{
    return PriceList::create(
        PriceListId::generate(),
        'Retail Bolivia',
        $channel,
    );
}

it('crea una lista de precios en Draft (BOB por defecto) y graba el evento', function (): void {
    $list = pricingCreateList();

    expect($list->status())->toBe(PriceListStatus::Draft)
        ->and($list->name())->toBe('Retail Bolivia')
        ->and($list->currency())->toBe('BOB')
        ->and($list->channel())->toBe(Channel::Retail);

    $events = $list->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PriceListCreated::class)
        ->and($events[0]->eventName())->toBe('pricing.price_list_created');
});

it('rechaza crear una lista sin nombre', function (): void {
    expect(fn () => PriceList::create(PriceListId::generate(), '  ', Channel::B2B))
        ->toThrow(PricingException::class, 'nombre');
});

it('se activa y se archiva grabando los cambios', function (): void {
    $list = pricingCreateList();
    $list->releaseEvents();

    $list->activate();
    expect($list->status())->toBe(PriceListStatus::Active);

    $list->archive();
    expect($list->status())->toBe(PriceListStatus::Archived);

    $events = $list->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(PriceListActivated::class)
        ->and($events[1])->toBeInstanceOf(PriceListArchived::class);
});

it('es idempotente al activar dos veces', function (): void {
    $list = pricingCreateList();
    $list->releaseEvents();

    $list->activate();
    $list->releaseEvents();
    $list->activate();

    expect($list->releaseEvents())->toBe([]);
});

it('es idempotente al archivar dos veces', function (): void {
    $list = pricingCreateList();
    $list->releaseEvents();

    $list->archive();
    $list->releaseEvents();
    $list->archive();

    expect($list->releaseEvents())->toBe([]);
});

it('no se puede activar una lista archivada', function (): void {
    $list = pricingCreateList();
    $list->archive();
    $list->releaseEvents();

    expect(fn () => $list->activate())->toThrow(PricingException::class, 'archivada');
});

it('fija un precio y graba el evento PriceSet', function (): void {
    $list = pricingCreateList();
    $list->activate();
    $list->releaseEvents();

    $list->setPrice(Sku::of('BEL-TV-55'), Money::of(450000));

    $events = $list->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PriceSet::class)
        ->and($events[0]->eventName())->toBe('pricing.price_set')
        ->and($events[0]->sku)->toBe('BEL-TV-55')
        ->and($events[0]->grossAmountCents)->toBe(450000)
        ->and($events[0]->currency)->toBe('BOB');
});

it('rechaza fijar un precio en otra moneda que la de la lista', function (): void {
    $list = pricingCreateList();
    $list->releaseEvents();

    expect(fn () => $list->setPrice(Sku::of('BEL-TV-55'), Money::of(450000, 'USD')))
        ->toThrow(PricingException::class, 'moneda');
});

it('una lista archivada no admite precios', function (): void {
    $list = pricingCreateList();
    $list->archive();
    $list->releaseEvents();

    expect(fn () => $list->setPrice(Sku::of('BEL-TV-55'), Money::of(450000)))
        ->toThrow(PricingException::class, 'archivada');
});
