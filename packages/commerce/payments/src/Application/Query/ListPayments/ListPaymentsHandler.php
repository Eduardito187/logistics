<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Query\ListPayments;

use Commerce\Payments\Application\ReadModel\PaymentView;
use Commerce\Payments\Domain\Contract\PaymentRepository;
use Commerce\Payments\Domain\Model\Payment;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de pagos como proyecciones de lectura, con filtro opcional por estado.
 *
 * @implements QueryHandler<ListPayments, list<PaymentView>>
 */
final readonly class ListPaymentsHandler implements QueryHandler
{
    public function __construct(
        private PaymentRepository $payments,
    ) {}

    /**
     * @return list<PaymentView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListPayments);

        $payments = $this->payments->all();
        if ($query->status !== null) {
            $payments = array_values(array_filter(
                $payments,
                static fn (Payment $payment): bool => $payment->status()->value === $query->status,
            ));
        }

        return array_map(static fn (Payment $payment): PaymentView => PaymentView::fromDomain($payment), $payments);
    }
}
