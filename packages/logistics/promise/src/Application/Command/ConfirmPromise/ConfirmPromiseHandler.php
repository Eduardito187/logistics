<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ConfirmPromise;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Confirma una promesa reservada. El dominio verifica contra el reloj que la ventana no venció; si
 * venció, lanza y la promesa queda para expirar.
 *
 * Al confirmar se concilia la franja prometida con el delivery slot del pedido (shipments): la promesa
 * confirmada es el compromiso en firme, así que se fija esa misma franja en el envío a través del puerto
 * OrderDeliverySlotGateway. La conciliación es best-effort y solo aplica cuando la franja tiene forma de
 * rango 'HH:MM-HH:MM' (una etiqueta como "AM"/"PM" no define ventana horaria del envío): un fallo del
 * contexto de envíos no revierte la confirmación —su savepoint anidado ya se deshizo—, para que el canal
 * no vea caer una confirmación válida por un problema aguas abajo.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<ConfirmPromise>
 */
final class ConfirmPromiseHandler implements CommandHandler
{
    public function __construct(
        private readonly PromiseRepository $promises,
        private readonly Clock $clock,
        private readonly RecordDomainEvents $recordEvents,
        private readonly OrderDeliverySlotGateway $orders,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ConfirmPromise) {
            throw PromiseException::because('ConfirmPromiseHandler solo maneja ConfirmPromise');
        }

        $promise = $this->promises->findById(PromiseId::fromString($command->promiseId));
        if ($promise === null) {
            throw PromiseException::because("no existe la promesa {$command->promiseId}");
        }

        $promise->confirm($this->clock->now());

        $this->promises->save($promise);

        $this->recordEvents->record($promise->releaseEvents(), 'promise.promise');

        $this->reconcileOrderSlot($promise);
    }

    /**
     * Fija en el pedido la franja prometida, para que el envío quede alineado con la promesa confirmada.
     * Solo cuando la franja es un rango horario convertible a ventana de entrega; best-effort.
     */
    private function reconcileOrderSlot(DeliveryPromise $promise): void
    {
        $window = $this->windowFromSlot($promise->slot()->timeSlot);
        if ($window === null) {
            return;
        }

        [$start, $end] = $window;

        try {
            $this->orders->assignSlot(
                $promise->shipmentRef(),
                $promise->slot()->dateString(),
                $start,
                $end,
            );
        } catch (PromiseException) {
            // El pedido no existe aguas abajo o ya no admite cambio de franja: no es motivo para tumbar
            // una confirmación válida. El savepoint anidado del dispatch ya revirtió el intento.
        }
    }

    /**
     * Descompone una franja 'HH:MM-HH:MM' en sus horas de inicio y fin; null si no tiene esa forma.
     *
     * @return array{0: string, 1: string}|null
     */
    private function windowFromSlot(string $timeSlot): ?array
    {
        $parts = explode('-', $timeSlot);
        if (count($parts) !== 2) {
            return null;
        }

        $start = $parts[0];
        $end = $parts[1];
        $clock = '/^([01][0-9]|2[0-3]):([0-5][0-9])$/';
        if (preg_match($clock, $start) !== 1 || preg_match($clock, $end) !== 1) {
            return null;
        }

        return [$start, $end];
    }
}
