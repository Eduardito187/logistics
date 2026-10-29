<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Command\CapturePayment;

use Commerce\Payments\Domain\Contract\PaymentRepository;
use Commerce\Payments\Domain\Exception\PaymentsException;
use Commerce\Payments\Domain\Model\Payment;
use Commerce\Payments\Domain\ValueObject\PaymentId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Resuelve el pago (por id o por orden), lo captura, lo persiste y publica sus eventos al outbox.
 *
 * Es idempotente: `Payment::capture()` no vuelve a grabar si el pago ya estaba capturado, así que un
 * webhook reentregado no duplica el hecho `payments.captured` ni la confirmación de la orden aguas abajo.
 *
 * @implements CommandHandler<CapturePayment>
 */
final readonly class CapturePaymentHandler implements CommandHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CapturePayment);

        $payment = $this->resolve($command);

        $payment->capture($command->gatewayRef);

        $this->payments->save($payment);

        $this->recordEvents->record($payment->releaseEvents(), 'payments.payment');
    }

    private function resolve(CapturePayment $command): Payment
    {
        if ($command->paymentId !== null) {
            $payment = $this->payments->findById(PaymentId::fromString($command->paymentId));
            if ($payment === null) {
                throw PaymentsException::because("No existe el pago {$command->paymentId}.");
            }

            return $payment;
        }

        if ($command->orderId !== null) {
            $payment = $this->payments->findByOrderId(Uuid::fromString($command->orderId));
            if ($payment === null) {
                throw PaymentsException::because("No hay un pago para la orden {$command->orderId}.");
            }

            return $payment;
        }

        throw PaymentsException::because('Capturar un pago exige el id del pago o el id de la orden.');
    }
}
