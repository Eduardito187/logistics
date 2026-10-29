<?php

declare(strict_types=1);

use Commerce\Payments\Application\Command\CapturePayment\CapturePayment;
use Commerce\Payments\Application\Command\InitiatePayment\InitiatePayment;
use Commerce\Payments\Application\Command\RefundPayment\RefundPayment;
use Commerce\Payments\Application\Query\GetPayment\GetPayment;
use Commerce\Payments\Application\Query\GetPaymentByOrder\GetPaymentByOrder;
use Commerce\Payments\Application\ReadModel\PaymentView;
use Commerce\Payments\Infrastructure\Persistence\Eloquent\Model\PaymentRecord;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Stack completo del contexto Payments contra logistics_test: command → handler → repositorio + pasarela
 * stub + outbox, y las consultas CQRS. Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function paymentsInitiateViaBus(CommandBus $bus, string $orderId, int $amountCents = 125000): string
{
    $bus->dispatch(new InitiatePayment(
        orderId: $orderId,
        amountCents: $amountCents,
        currency: 'BOB',
        method: 'card',
    ));

    /** @var PaymentRecord $record */
    $record = PaymentRecord::query()->where('order_id', $orderId)->firstOrFail();

    return (string) $record->getKey();
}

it('inicia un pago con el stub (queda autorizado) y encola el evento en el outbox', function (): void {
    $orderId = Uuid::generate()->value;
    $id = paymentsInitiateViaBus($this->commands, $orderId);

    // El stub aprueba siempre → el pago queda autorizado con referencia del proveedor.
    $this->assertDatabaseHas('commerce_payments', [
        'id' => $id,
        'order_id' => $orderId,
        'amount_cents' => 125000,
        'currency' => 'BOB',
        'method' => 'card',
        'status' => 'authorized',
    ]);

    $record = PaymentRecord::query()->findOrFail($id);
    expect($record->gateway_ref)->not->toBeNull();

    expect(DB::table('outbox_messages')->where('event_name', 'payments.initiated')->count())
        ->toBeGreaterThanOrEqual(1);
});

it('captura por el handler y deja el pago en captured con el hecho payments.captured', function (): void {
    $orderId = Uuid::generate()->value;
    $id = paymentsInitiateViaBus($this->commands, $orderId);

    $this->commands->dispatch(new CapturePayment(
        gatewayRef: 'GW-WEBHOOK-1',
        orderId: $orderId,
    ));

    $this->assertDatabaseHas('commerce_payments', [
        'id' => $id,
        'status' => 'captured',
        'gateway_ref' => 'GW-WEBHOOK-1',
    ]);

    expect(DB::table('outbox_messages')->where('event_name', 'payments.captured')->count())
        ->toBeGreaterThanOrEqual(1);

    /** @var PaymentView|null $view */
    $view = $this->queries->ask(new GetPayment(paymentId: $id));
    expect($view)->not->toBeNull()
        ->and($view->toArray()['status'])->toBe('captured');

    /** @var PaymentView|null $byOrder */
    $byOrder = $this->queries->ask(new GetPaymentByOrder(orderId: $orderId));
    expect($byOrder)->not->toBeNull()
        ->and($byOrder->id)->toBe($id);
});

it('capturar dos veces es idempotente (un solo pago capturado)', function (): void {
    $orderId = Uuid::generate()->value;
    $id = paymentsInitiateViaBus($this->commands, $orderId);

    $this->commands->dispatch(new CapturePayment(gatewayRef: 'GW-DUP', orderId: $orderId));
    $this->commands->dispatch(new CapturePayment(gatewayRef: 'GW-DUP', orderId: $orderId));

    expect(PaymentRecord::query()->where('order_id', $orderId)->where('status', 'captured')->count())
        ->toBe(1);
});

it('reembolsa un pago capturado', function (): void {
    $orderId = Uuid::generate()->value;
    $id = paymentsInitiateViaBus($this->commands, $orderId);
    $this->commands->dispatch(new CapturePayment(gatewayRef: 'GW-REF', orderId: $orderId));

    $this->commands->dispatch(new RefundPayment(paymentId: $id));

    $this->assertDatabaseHas('commerce_payments', ['id' => $id, 'status' => 'refunded']);
    expect(DB::table('outbox_messages')->where('event_name', 'payments.refunded')->count())
        ->toBeGreaterThanOrEqual(1);
});
