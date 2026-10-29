<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Query\GetPayment;

use Commerce\Payments\Application\ReadModel\PaymentView;
use Commerce\Payments\Domain\Contract\PaymentRepository;
use Commerce\Payments\Domain\ValueObject\PaymentId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve un pago por su id, o null si no existe.
 *
 * @implements QueryHandler<GetPayment, PaymentView|null>
 */
final readonly class GetPaymentHandler implements QueryHandler
{
    public function __construct(
        private PaymentRepository $payments,
    ) {}

    public function handle(Query $query): ?PaymentView
    {
        assert($query instanceof GetPayment);

        $payment = $this->payments->findById(PaymentId::fromString($query->paymentId));

        return $payment !== null ? PaymentView::fromDomain($payment) : null;
    }
}
