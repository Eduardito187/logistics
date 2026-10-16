<?php

declare(strict_types=1);

use Logistics\Promise\Infrastructure\Fulfillment\ConfigurableFulfillmentLeadTimeProvider;
use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shared\Domain\ValueObject\Uuid;

it('adds no lead time for retail (owned) stock', function (): void {
    $provider = new ConfigurableFulfillmentLeadTimeProvider(2, 3);

    $lead = $provider->leadTimeFor(Ownership::dismacOwned());

    expect($lead->isZero())->toBeTrue()
        ->and($lead->minutes)->toBe(0);
});

it('adds the fbd receipt lead for a seller fulfilled by dismac', function (): void {
    $provider = new ConfigurableFulfillmentLeadTimeProvider(2, 3);

    $lead = $provider->leadTimeFor(Ownership::sellerFbd(Uuid::generate()));

    // 2 días = 2880 minutos.
    expect($lead->minutes)->toBe(2880);
});

it('adds the dropship lead for a seller that ships directly', function (): void {
    $provider = new ConfigurableFulfillmentLeadTimeProvider(2, 3);

    $lead = $provider->leadTimeFor(Ownership::sellerDropship(Uuid::generate()));

    // 3 días = 4320 minutos.
    expect($lead->minutes)->toBe(4320);
});
