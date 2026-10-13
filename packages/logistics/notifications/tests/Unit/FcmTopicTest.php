<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\FcmTopic;

it('builds the topic from a shipment id with the shipment_ prefix', function (): void {
    $topic = FcmTopic::forShipment('abc-123');

    expect($topic->value)->toBe('shipment_abc-123')
        ->and((string) $topic)->toBe('shipment_abc-123');
});

it('trims the shipment id before building the topic', function (): void {
    expect(FcmTopic::forShipment('  abc-123  ')->value)->toBe('shipment_abc-123');
});

it('rejects an empty shipment id', function (): void {
    expect(fn () => FcmTopic::forShipment('   '))
        ->toThrow(NotificationsException::class, 'no puede estar vacío');
});
