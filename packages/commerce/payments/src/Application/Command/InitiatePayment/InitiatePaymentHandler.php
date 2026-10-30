<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Command\InitiatePayment;

use Commerce\Payments\Domain\Contract\PaymentGateway;
use Commerce\Payments\Domain\Contract\PaymentRepository;
use Commerce\Payments\Domain\Model\Payment;
use Commerce\Payments\Domain\ValueObject\Money;
use Commerce\Payments\Domain\ValueObject\PaymentIntent;
use Commerce\Payments\Domain\ValueObject\PaymentMethod;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Crea el pago, intenta el cobro contra la pasarela y persiste el resultado publicando eventos al outbox.
 *
 * Según lo que devuelva la pasarela, el pago queda Authorized (aprobación online, p. ej. tarjeta) o
 * sigue Pending (flujos async como QR BCB, que se capturan después por webbook). Nunca falla el pago
 * acá: el rechazo explícito llega por el comando FailPayment. El save y el append al outbox corren en la
 * misma transacción que abre el decorador transaccional del CommandBus (Infrastructure).
 *
 * @implements CommandHandler<InitiatePayment>
 */
final readonly class InitiatePaymentHandler implements CommandHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof InitiatePayment);

        $orderId = Uuid::fromString($command->orderId);
        $amount = Money::of($command->amountCents, $command->currency);
        $method = PaymentMethod::from($command->method);

        $payment = Payment::initiate($this->payments->nextIdentity(), $orderId, $amount, $method);

        $result = $this->gateway->charge(PaymentIntent::of(
            $payment->id(),
            $orderId,
            $amount,
            $method,
        ));

        if ($result->approved && $result->gatewayRef !== null) {
            $payment->markAuthorized($result->gatewayRef);
        }

        $this->payments->save($payment);

        $this->recordEvents->record($payment->releaseEvents(), 'payments.payment');
    }
}
