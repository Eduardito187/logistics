<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Query\GetPaymentByOrder;

use Commerce\Payments\Application\ReadModel\PaymentView;
use Commerce\Payments\Domain\Contract\PaymentRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Devuelve el pago más reciente de una orden, o null si no hay ninguno.
 *
 * @implements QueryHandler<GetPaymentByOrder, PaymentView|null>
 */
final readonly class GetPaymentByOrderHandler implements QueryHandler
{
    public function __construct(
        private PaymentRepository $payments,
    ) {}

    public function handle(Query $query): ?PaymentView
    {
        assert($query instanceof GetPaymentByOrder);

        $payment = $this->payments->findByOrderId(Uuid::fromString($query->orderId));

        return $payment !== null ? PaymentView::fromDomain($payment) : null;
    }
}
