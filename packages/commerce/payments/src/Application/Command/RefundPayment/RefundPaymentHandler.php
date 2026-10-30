<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Command\RefundPayment;

use Commerce\Payments\Domain\Contract\PaymentRepository;
use Commerce\Payments\Domain\Exception\PaymentsException;
use Commerce\Payments\Domain\ValueObject\PaymentId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el pago, lo reembolsa, lo persiste y publica sus eventos al outbox. Falla si no existe o si el
 * pago no estaba capturado (la invariante vive en el agregado).
 *
 * @implements CommandHandler<RefundPayment>
 */
final readonly class RefundPaymentHandler implements CommandHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RefundPayment);

        $payment = $this->payments->findById(PaymentId::fromString($command->paymentId));
        if ($payment === null) {
            throw PaymentsException::because("No existe el pago {$command->paymentId}.");
        }

        $payment->refund();

        $this->payments->save($payment);

        $this->recordEvents->record($payment->releaseEvents(), 'payments.payment');
    }
}
