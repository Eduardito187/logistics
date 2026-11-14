<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Application\Contract\ReturnCoverageReader;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Reactor outbox: al iniciarse una devolución (`returns.return_initiated`) el seguimiento del pedido
 * entregado pasa a «devuelta». El `original_reference` referencia la orden/envío original (orderId o
 * orderId#n:nodeId): se deriva el `orderReference` quitando «#n:». Tolerante: solo aplica desde «entregado».
 * Solo cuando la devolución cubre el pedido COMPLETO; una parcial lo deja «entregado».
 */
final readonly class ReturnedOnReturnInitiated implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
        private ReturnCoverageReader $coverage,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'returns.return_initiated') {
            return;
        }

        $payload = $message->payload();
        $reference = is_string($payload['original_reference'] ?? null) ? $payload['original_reference'] : '';
        if ($reference === '') {
            return;
        }

        $orderReference = explode('#n:', $reference)[0];

        // Devolución PARCIAL (p. ej. 2 de 3 productos): el pedido sigue «entregado» y los productos que no
        // se devolvieron conservan su historia. Recién cuando se devolvió completo pasa a «devuelta».
        if (!$this->coverage->isFullyReturned($orderReference)) {
            return;
        }

        $this->commandBus->dispatch(new AdvanceTracking(
            status: VisibleStatus::Returned,
            orderReference: $orderReference,
        ));
    }
}
