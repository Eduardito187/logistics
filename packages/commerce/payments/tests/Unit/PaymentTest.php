<?php

declare(strict_types=1);

use Commerce\Payments\Domain\Event\PaymentCaptured;
use Commerce\Payments\Domain\Event\PaymentFailed;
use Commerce\Payments\Domain\Event\PaymentInitiated;
use Commerce\Payments\Domain\Event\PaymentRefunded;
use Commerce\Payments\Domain\Exception\PaymentsException;
use Commerce\Payments\Domain\Model\Payment;
use Commerce\Payments\Domain\ValueObject\Money;
use Commerce\Payments\Domain\ValueObject\PaymentId;
use Commerce\Payments\Domain\ValueObject\PaymentMethod;
use Commerce\Payments\Domain\ValueObject\PaymentStatus;
use Logistics\Shared\Domain\ValueObject\Uuid;

function paymentsInitiate(?Uuid $orderId = null): Payment
{
    return Payment::initiate(
        PaymentId::generate(),
        $orderId ?? Uuid::generate(),
        Money::of(125000),
        PaymentMethod::QrBcb,
    );
}

it('inicia un pago en pending y graba el evento', function (): void {
    $orderId = Uuid::generate();
    $payment = paymentsInitiate($orderId);

    expect($payment->status())->toBe(PaymentStatus::Pending)
        ->and($payment->amount()->cents)->toBe(125000)
        ->and($payment->amount()->currency)->toBe('BOB')
        ->and($payment->method())->toBe(PaymentMethod::QrBcb)
        ->and($payment->gatewayRef())->toBeNull()
        ->and($payment->orderId()->value)->toBe($orderId->value);

    $events = $payment->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PaymentInitiated::class)
        ->and($events[0]->eventName())->toBe('payments.initiated')
        ->and($events[0]->payload()['status'])->toBe('pending');
});

it('autoriza desde pending guardando la referencia sin grabar evento', function (): void {
    $payment = paymentsInitiate();
    $payment->releaseEvents();

    $payment->markAuthorized('GW-AUTH-1');

    expect($payment->status())->toBe(PaymentStatus::Authorized)
        ->and($payment->gatewayRef())->toBe('GW-AUTH-1')
        ->and($payment->releaseEvents())->toBe([]);
});

it('captura e informa payments.captured con el payload para confirmar la orden', function (): void {
    $orderId = Uuid::generate();
    $payment = paymentsInitiate($orderId);
    $payment->releaseEvents();

    $payment->capture('GW-CAP-9');

    expect($payment->status())->toBe(PaymentStatus::Captured)
        ->and($payment->gatewayRef())->toBe('GW-CAP-9');

    $events = $payment->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PaymentCaptured::class)
        ->and($events[0]->eventName())->toBe('payments.captured')
        ->and($events[0]->payload())->toBe([
            'order_id' => $orderId->value,
            'amount_cents' => 125000,
            'currency' => 'BOB',
            'gateway_ref' => 'GW-CAP-9',
        ]);
});

it('es idempotente al capturar dos veces (no re-graba)', function (): void {
    $payment = paymentsInitiate();
    $payment->capture('GW-CAP-1');
    $payment->releaseEvents();

    $payment->capture('GW-CAP-1');

    expect($payment->status())->toBe(PaymentStatus::Captured)
        ->and($payment->releaseEvents())->toBe([]);
});

it('falla un pago pendiente grabando el motivo', function (): void {
    $orderId = Uuid::generate();
    $payment = paymentsInitiate($orderId);
    $payment->releaseEvents();

    $payment->fail('declined_by_gateway');

    expect($payment->status())->toBe(PaymentStatus::Failed);

    $events = $payment->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PaymentFailed::class)
        ->and($events[0]->eventName())->toBe('payments.failed')
        ->and($events[0]->payload())->toBe([
            'order_id' => $orderId->value,
            'reason' => 'declined_by_gateway',
        ]);
});

it('reembolsa solo un pago capturado grabando el evento', function (): void {
    $orderId = Uuid::generate();
    $payment = paymentsInitiate($orderId);
    $payment->capture('GW-CAP-2');
    $payment->releaseEvents();

    $payment->refund();

    expect($payment->status())->toBe(PaymentStatus::Refunded);

    $events = $payment->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PaymentRefunded::class)
        ->and($events[0]->eventName())->toBe('payments.refunded')
        ->and($events[0]->payload())->toBe([
            'order_id' => $orderId->value,
            'amount_cents' => 125000,
            'currency' => 'BOB',
        ]);
});

it('rechaza transiciones inválidas con PaymentsException', function (): void {
    // No se puede reembolsar un pago pendiente (nunca se capturó).
    expect(fn () => paymentsInitiate()->refund())->toThrow(PaymentsException::class);

    // No se puede autorizar un pago ya capturado.
    $captured = paymentsInitiate();
    $captured->capture('GW-X');
    expect(fn () => $captured->markAuthorized('GW-Y'))->toThrow(PaymentsException::class);

    // No se puede capturar un pago fallido.
    $failed = paymentsInitiate();
    $failed->fail('nope');
    expect(fn () => $failed->capture('GW-Z'))->toThrow(PaymentsException::class);

    // No se puede fallar un pago ya capturado.
    $done = paymentsInitiate();
    $done->capture('GW-W');
    expect(fn () => $done->fail('late'))->toThrow(PaymentsException::class);
});
