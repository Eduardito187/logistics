<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\ValueObject\TransferStatus;

/**
 * Estado de un traspaso de stock a SAP (ADR 002): despachabilidad y terminalidad. PHP puro.
 */

it('expone los cuatro estados con su valor de catálogo', function (): void {
    expect(TransferStatus::Pending->value)->toBe('PENDING')
        ->and(TransferStatus::Sent->value)->toBe('SENT')
        ->and(TransferStatus::Acknowledged->value)->toBe('ACKNOWLEDGED')
        ->and(TransferStatus::Failed->value)->toBe('FAILED');
});

it('solo Pending y Failed son despachables por el worker', function (): void {
    expect(TransferStatus::Pending->isDispatchable())->toBeTrue()
        ->and(TransferStatus::Failed->isDispatchable())->toBeTrue()
        ->and(TransferStatus::Sent->isDispatchable())->toBeFalse()
        ->and(TransferStatus::Acknowledged->isDispatchable())->toBeFalse();
});

it('solo Acknowledged es terminal', function (): void {
    expect(TransferStatus::Acknowledged->isTerminal())->toBeTrue()
        ->and(TransferStatus::Pending->isTerminal())->toBeFalse()
        ->and(TransferStatus::Sent->isTerminal())->toBeFalse()
        ->and(TransferStatus::Failed->isTerminal())->toBeFalse();
});
