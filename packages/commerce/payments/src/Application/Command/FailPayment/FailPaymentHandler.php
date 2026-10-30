<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Command\FailPayment;

use Commerce\Payments\Domain\Contract\PaymentRepository;
use Commerce\Payments\Domain\Exception\PaymentsException;
use Commerce\Payments\Domain\ValueObject\PaymentId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el pago, lo marca como fallido, lo persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * @implements CommandHandler<FailPayment>
 */
final readonly class FailPaymentHandler implements CommandHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof FailPayment);

        $payment = $this->payments->findById(PaymentId::fromString($command->paymentId));
        if ($payment === null) {
            throw PaymentsException::because("No existe el pago {$command->paymentId}.");
        }

        $payment->fail($command->reason);

        $this->payments->save($payment);

        $this->recordEvents->record($payment->releaseEvents(), 'payments.payment');
    }
}
