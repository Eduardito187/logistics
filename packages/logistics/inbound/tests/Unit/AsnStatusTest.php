<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\ValueObject\AsnStatus;

it('expone los siete estados del ciclo de vida con su valor primitivo', function (): void {
    expect(AsnStatus::Announced->value)->toBe('announced')
        ->and(AsnStatus::Scheduled->value)->toBe('scheduled')
        ->and(AsnStatus::Arrived->value)->toBe('arrived')
        ->and(AsnStatus::Receiving->value)->toBe('receiving')
        ->and(AsnStatus::Received->value)->toBe('received')
        ->and(AsnStatus::Closed->value)->toBe('closed')
        ->and(AsnStatus::Cancelled->value)->toBe('cancelled')
        ->and(AsnStatus::cases())->toHaveCount(7);
});

it('da la etiqueta en inglés de cada estado', function (AsnStatus $status, string $label): void {
    expect($status->label())->toBe($label);
})->with([
    'announced' => [AsnStatus::Announced, 'Announced'],
    'scheduled' => [AsnStatus::Scheduled, 'Scheduled'],
    'arrived' => [AsnStatus::Arrived, 'Arrived'],
    'receiving' => [AsnStatus::Receiving, 'Receiving'],
    'received' => [AsnStatus::Received, 'Received'],
    'closed' => [AsnStatus::Closed, 'Closed'],
    'cancelled' => [AsnStatus::Cancelled, 'Cancelled'],
]);

it('marca como terminales solo Closed y Cancelled', function (): void {
    expect(AsnStatus::Closed->isTerminal())->toBeTrue()
        ->and(AsnStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(AsnStatus::Announced->isTerminal())->toBeFalse()
        ->and(AsnStatus::Scheduled->isTerminal())->toBeFalse()
        ->and(AsnStatus::Arrived->isTerminal())->toBeFalse()
        ->and(AsnStatus::Receiving->isTerminal())->toBeFalse()
        ->and(AsnStatus::Received->isTerminal())->toBeFalse();
});
