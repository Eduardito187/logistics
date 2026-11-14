<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Event\VoucherExpired;
use Logistics\Compensation\Domain\Event\VoucherIssued;
use Logistics\Compensation\Domain\Event\VoucherRedeemed;
use Logistics\Compensation\Domain\Event\VoucherRevoked;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\Model\Voucher;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;
use Logistics\Compensation\Domain\ValueObject\VoucherId;
use Logistics\Compensation\Domain\ValueObject\VoucherStatus;

// Agregado VALE: un solo uso, canje idempotente por sale_ref, anular/vencer solo antes del canje.

function issueVoucher(int $amountCents = 3_000, ?string $email = null, ?DateTimeImmutable $expiresAt = null): Voucher
{
    return Voucher::issue(
        id: VoucherId::generate(),
        caseId: CompensationCaseId::generate(),
        code: VoucherCode::generate(),
        amount: Money::of($amountCents),
        holderEmail: $email,
        expiresAt: $expiresAt ?? new DateTimeImmutable('+90 days'),
        orderReference: 'ORD-1',
        trigger: 'DAMAGED_PRODUCT',
    );
}

it('emite un vale emitido con la sincronización pendiente y graba VoucherIssued', function (): void {
    $voucher = issueVoucher(email: 'Cliente@Dismac.test');

    expect($voucher->status())->toBe(VoucherStatus::Issued)
        ->and($voucher->magentoSyncStatus())->toBe(Voucher::SYNC_PENDING)
        ->and($voucher->holderEmail())->toBe('cliente@dismac.test')
        ->and($voucher->holderEmailHash())->not->toBeNull();

    $events = $voucher->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(VoucherIssued::class)
        ->and($voucher->releaseEvents())->toBe([]);
});

it('rechaza emitir un vale de monto cero', function (): void {
    expect(fn () => issueVoucher(amountCents: 0))->toThrow(CompensationException::class);
});

it('reserva y libera sin canjear', function (): void {
    $voucher = issueVoucher();
    $now = new DateTimeImmutable;

    $voucher->hold('pos', $now->modify('+10 minutes'), $now);
    expect($voucher->heldUntil())->not->toBeNull()
        ->and($voucher->heldByChannel())->toBe('pos');

    $voucher->releaseHold();
    expect($voucher->heldUntil())->toBeNull()
        ->and($voucher->heldByChannel())->toBeNull();
});

it('rechaza una segunda reserva vigente', function (): void {
    $voucher = issueVoucher();
    $now = new DateTimeImmutable;
    $voucher->hold('pos', $now->modify('+10 minutes'), $now);

    expect(fn () => $voucher->hold('web', $now->modify('+10 minutes'), $now))
        ->toThrow(CompensationException::class);
});

it('canjea el vale una vez y graba VoucherRedeemed', function (): void {
    $voucher = issueVoucher();
    $voucher->releaseEvents();
    $now = new DateTimeImmutable;

    $voucher->redeem('pos', 'SALE-1', Money::of(3_000), $now);

    expect($voucher->status())->toBe(VoucherStatus::Redeemed)
        ->and($voucher->redeemedSaleRef())->toBe('SALE-1')
        ->and($voucher->redeemedChannel())->toBe('pos')
        ->and($voucher->heldUntil())->toBeNull();

    $events = $voucher->releaseEvents();
    expect($events[0])->toBeInstanceOf(VoucherRedeemed::class);
});

it('el canje es idempotente por sale_ref', function (): void {
    $voucher = issueVoucher();
    $now = new DateTimeImmutable;
    $voucher->redeem('pos', 'SALE-1', Money::of(3_000), $now);
    $voucher->releaseEvents();

    $voucher->redeem('pos', 'SALE-1', Money::of(3_000), $now);

    expect($voucher->status())->toBe(VoucherStatus::Redeemed)
        ->and($voucher->releaseEvents())->toBe([]);
});

it('rechaza un segundo canje con otra referencia', function (): void {
    $voucher = issueVoucher();
    $now = new DateTimeImmutable;
    $voucher->redeem('pos', 'SALE-1', Money::of(3_000), $now);

    expect(fn () => $voucher->redeem('pos', 'SALE-2', Money::of(3_000), $now))
        ->toThrow(CompensationException::class);
});

it('rechaza canjear sin referencia de venta', function (): void {
    $voucher = issueVoucher();

    expect(fn () => $voucher->redeem('pos', '  ', Money::of(1_000), new DateTimeImmutable))
        ->toThrow(CompensationException::class);
});

it('rechaza canjear más que el monto del vale', function (): void {
    $voucher = issueVoucher(amountCents: 3_000);

    expect(fn () => $voucher->redeem('pos', 'SALE-1', Money::of(5_000), new DateTimeImmutable))
        ->toThrow(CompensationException::class);
});

it('anula un vale emitido y es idempotente', function (): void {
    $voucher = issueVoucher();
    $voucher->releaseEvents();

    $voucher->revoke('supervisor-1', 'Pedido cancelado.');
    expect($voucher->status())->toBe(VoucherStatus::Revoked);

    $events = $voucher->releaseEvents();
    expect($events[0])->toBeInstanceOf(VoucherRevoked::class);

    // Segunda anulación: no-op (no graba otro evento).
    $voucher->revoke('supervisor-1', 'otra vez');
    expect($voucher->releaseEvents())->toBe([]);
});

it('rechaza anular un vale ya canjeado', function (): void {
    $voucher = issueVoucher();
    $voucher->redeem('pos', 'SALE-1', Money::of(3_000), new DateTimeImmutable);

    expect(fn () => $voucher->revoke('supervisor-1', 'motivo'))->toThrow(CompensationException::class);
});

it('rechaza anular con motivo vacío', function (): void {
    $voucher = issueVoucher();

    expect(fn () => $voucher->revoke('supervisor-1', '   '))->toThrow(CompensationException::class);
});

it('vence un vale emitido cuya fecha ya pasó', function (): void {
    $voucher = issueVoucher(expiresAt: new DateTimeImmutable('-1 day'));
    $voucher->releaseEvents();

    expect($voucher->expire(new DateTimeImmutable))->toBeTrue()
        ->and($voucher->status())->toBe(VoucherStatus::Expired);

    $events = $voucher->releaseEvents();
    expect($events[0])->toBeInstanceOf(VoucherExpired::class);
});

it('no vence un vale cuya fecha no llegó', function (): void {
    $voucher = issueVoucher(expiresAt: new DateTimeImmutable('+10 days'));

    expect($voucher->expire(new DateTimeImmutable))->toBeFalse()
        ->and($voucher->status())->toBe(VoucherStatus::Issued);
});

it('rechaza canjear un vale vencido', function (): void {
    $voucher = issueVoucher(expiresAt: new DateTimeImmutable('-1 day'));

    expect(fn () => $voucher->redeem('pos', 'SALE-1', Money::of(1_000), new DateTimeImmutable))
        ->toThrow(CompensationException::class);
});

it('registra el resultado del sync y rechaza un resultado inválido', function (): void {
    $voucher = issueVoucher();

    $voucher->recordSync(Voucher::SYNC_SYNCED, 'rule-123');
    expect($voucher->magentoSyncStatus())->toBe(Voucher::SYNC_SYNCED)
        ->and($voucher->magentoRuleId())->toBe('rule-123');

    expect(fn () => $voucher->recordSync('unknown'))->toThrow(CompensationException::class);
});

it('valida vigencia y titularidad', function (): void {
    $withHolder = issueVoucher(email: 'cliente@dismac.test');
    $anonymous = issueVoucher();

    expect($withHolder->isValidAt(new DateTimeImmutable))->toBeTrue()
        ->and($withHolder->belongsTo('Cliente@Dismac.test'))->toBeTrue()
        ->and($withHolder->belongsTo('otro@dismac.test'))->toBeFalse()
        ->and($anonymous->belongsTo('cualquiera@dismac.test'))->toBeTrue();
});

it('reconstituye un vale desde persistencia sin grabar eventos', function (): void {
    $voucher = Voucher::reconstitute(
        id: VoucherId::generate(),
        caseId: CompensationCaseId::generate(),
        code: VoucherCode::generate(),
        amount: Money::of(3_000),
        holderEmail: 'cliente@dismac.test',
        holderEmailHash: hash('sha256', 'cliente@dismac.test'),
        expiresAt: new DateTimeImmutable('+10 days'),
        status: VoucherStatus::Issued,
        magentoRuleId: 'rule-1',
        magentoSyncStatus: Voucher::SYNC_SYNCED,
        heldUntil: null,
        heldByChannel: null,
        redeemedAt: null,
        redeemedChannel: null,
        redeemedSaleRef: null,
    );

    expect($voucher->status())->toBe(VoucherStatus::Issued)
        ->and($voucher->magentoRuleId())->toBe('rule-1')
        ->and($voucher->releaseEvents())->toBe([]);
});
