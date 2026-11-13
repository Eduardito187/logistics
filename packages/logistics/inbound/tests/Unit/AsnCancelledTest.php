<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\Event\AsnCancelled;

it('construye el evento de cancelación con su motivo y lo expone en el payload', function (): void {
    $event = new AsnCancelled('01931000-0000-7000-8000-000000000001', 'seller no se presentó');

    expect($event->aggregateId())->toBe('01931000-0000-7000-8000-000000000001')
        ->and($event->reason)->toBe('seller no se presentó')
        ->and($event->eventName())->toBe('inbound.asn_cancelled')
        ->and($event->payload())->toBe(['reason' => 'seller no se presentó']);
});

it('admite cancelación sin motivo (reason null)', function (): void {
    $event = new AsnCancelled('01931000-0000-7000-8000-000000000002', null);

    expect($event->reason)->toBeNull()
        ->and($event->payload())->toBe(['reason' => null]);
});
