<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Event\SettlementApproved;
use Logistics\Carriers\Domain\Event\SettlementDisputed;
use Logistics\Carriers\Domain\Event\SettlementDocumentAttached;
use Logistics\Carriers\Domain\Event\SettlementPaid;
use Logistics\Carriers\Domain\Event\SettlementSubmitted;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\Model\Settlement;
use Logistics\Carriers\Domain\ValueObject\BillingPeriod;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\Money;
use Logistics\Carriers\Domain\ValueObject\SettlementId;
use Logistics\Carriers\Domain\ValueObject\SettlementStatus;

function enviarLiquidacion(): Settlement
{
    return Settlement::submit(
        SettlementId::generate(),
        'carrier-123',
        CorridorRef::of('cd-scz', 'cd-lpz', 'cd_to_cd'),
        BillingPeriod::of('2026-10'),
        Money::of(980_000, 'BOB'),
        'INV-00042',
    );
}

it('envía una liquidación en estado Submitted y graba el evento', function (): void {
    $settlement = enviarLiquidacion();

    expect($settlement->status())->toBe(SettlementStatus::Submitted)
        ->and($settlement->carrierId())->toBe('carrier-123')
        ->and($settlement->period()->toString())->toBe('2026-10')
        ->and($settlement->amount()->cents)->toBe(980_000)
        ->and($settlement->reference())->toBe('INV-00042');

    $events = $settlement->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(SettlementSubmitted::class)
        ->and($events[0]->eventName())->toBe('carriers.settlement_submitted');
});

it('rechaza enviar sin transportista, sin referencia o con monto no positivo', function (callable $build, string $reason): void {
    expect($build)->toThrow(CarriersException::class, $reason);
})->with([
    'sin transportista' => [fn () => Settlement::submit(SettlementId::generate(), '  ', CorridorRef::of('a', 'b', 'cd_to_cd'), BillingPeriod::of('2026-10'), Money::of(1, 'BOB'), 'INV-1'), 'transportista'],
    'sin referencia' => [fn () => Settlement::submit(SettlementId::generate(), 'c-1', CorridorRef::of('a', 'b', 'cd_to_cd'), BillingPeriod::of('2026-10'), Money::of(1, 'BOB'), '  '), 'referencia'],
    'monto cero' => [fn () => Settlement::submit(SettlementId::generate(), 'c-1', CorridorRef::of('a', 'b', 'cd_to_cd'), BillingPeriod::of('2026-10'), Money::of(0, 'BOB'), 'INV-1'), 'mayor que cero'],
]);

it('aprueba y luego paga una liquidación grabando los eventos', function (): void {
    $settlement = enviarLiquidacion();
    $settlement->releaseEvents();

    $settlement->approve();
    expect($settlement->status())->toBe(SettlementStatus::Approved);

    $settlement->markPaid('PAY-900');
    expect($settlement->status())->toBe(SettlementStatus::Paid)
        ->and($settlement->paymentReference())->toBe('PAY-900');

    $events = $settlement->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(SettlementApproved::class)
        ->and($events[1])->toBeInstanceOf(SettlementPaid::class);
});

it('no permite pagar una liquidación que no está aprobada', function (): void {
    $settlement = enviarLiquidacion();

    expect(fn () => $settlement->markPaid('PAY-1'))
        ->toThrow(CarriersException::class, 'aprobada');
});

it('objeta una liquidación y permite volver a aprobarla', function (): void {
    $settlement = enviarLiquidacion();
    $settlement->releaseEvents();

    $settlement->dispute('Weight mismatch');
    expect($settlement->status())->toBe(SettlementStatus::Disputed)
        ->and($settlement->disputeReason())->toBe('Weight mismatch');

    $settlement->approve();
    expect($settlement->status())->toBe(SettlementStatus::Approved)
        ->and($settlement->disputeReason())->toBeNull();

    $events = $settlement->releaseEvents();
    expect($events[0])->toBeInstanceOf(SettlementDisputed::class)
        ->and($events[1])->toBeInstanceOf(SettlementApproved::class);
});

it('no permite objetar una liquidación ya pagada', function (): void {
    $settlement = enviarLiquidacion();
    $settlement->approve();
    $settlement->markPaid('PAY-1');

    expect(fn () => $settlement->dispute('late'))
        ->toThrow(CarriersException::class, 'pagada');
});

it('adjunta documentos mientras no esté pagada y graba el evento', function (): void {
    $settlement = enviarLiquidacion();
    $settlement->releaseEvents();

    $settlement->attachDocument(['type' => 'invoice', 'reference' => 'INV-00042.pdf']);
    expect($settlement->documents())->toHaveCount(1);

    $events = $settlement->releaseEvents();
    expect($events[0])->toBeInstanceOf(SettlementDocumentAttached::class);
});
