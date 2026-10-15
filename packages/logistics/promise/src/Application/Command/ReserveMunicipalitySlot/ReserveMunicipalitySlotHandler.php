<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ReserveMunicipalitySlot;

use DateTimeImmutable;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchGate;
use Logistics\Promise\Domain\Contract\MunicipalitySlotReserver;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Service\CustomerLocationResolver;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Orquesta la selección de slot del cliente de punta a punta: resuelve el municipio del envío, valida que
 * el día sea despachable, descuenta una entrega del cupo del municipio (capacity) y fija la franja en el
 * envío (shipments). Todo dentro de la misma transacción del CommandBus, así los dos efectos son atómicos:
 * si algo falla, no queda ni cupo tomado ni franja puesta.
 *
 * Idempotente: si el envío ya tiene EXACTAMENTE esa franja, no vuelve a consumir cupo ni a emitir cambios.
 * Si tenía OTRA franja, primero devuelve el cupo viejo y luego toma el nuevo (reprogramación).
 *
 * Distingue los dos rechazos que el canal necesita diferenciar:
 *  - día no despachable (bloqueado por feriado/otro motivo o fuera del horizonte activo) → día no disponible;
 *  - cupo agotado para una franja de un día despachable → franja ya tomada.
 * Por eso valida el día ANTES de consumir: cuando la reserva devuelve false ya es, con certeza, cupo agotado.
 *
 * El cruce a coverage/capacity/shipments es por puertos y por el bus; este handler es PHP puro.
 *
 * @implements CommandHandler<ReserveMunicipalitySlot>
 */
final readonly class ReserveMunicipalitySlotHandler implements CommandHandler
{
    public function __construct(
        private CustomerLocationResolver $location,
        private MunicipalityDispatchGate $dispatchGate,
        private MunicipalitySlotReserver $slotReserver,
        private OrderDeliverySlotGateway $orders,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReserveMunicipalitySlot);

        $municipalityId = $this->location->resolve(
            $command->municipalityId,
            $command->latitude,
            $command->longitude,
            $command->shipmentId,
        );

        if ($municipalityId === null) {
            throw PromiseException::because('no se pudo resolver el municipio del envío: la dirección no cae en ninguna zona de cobertura');
        }

        $requestedSlot = $this->slotFrom($command->date, $command->windowStart, $command->windowEnd);

        // Idempotencia: si el envío ya tiene esa franja, nada que reservar ni cambiar (reservar dos veces
        // el mismo slot es inocuo; no se vuelve a consumir cupo).
        $current = $this->orders->currentSlot($command->shipmentId);
        if ($this->isSameSlot($current, $command)) {
            return;
        }

        // Día no despachable → "día no disponible" (422), distinto de cupo agotado. Se valida antes de
        // tocar cupo para que un false posterior de reserve() signifique, sin ambigüedad, agotamiento.
        if (!$this->dispatchGate->isDispatchable($municipalityId, $requestedSlot->date)) {
            throw PromiseException::dayUnavailable("el día {$command->date} no está disponible para despacho en el municipio (bloqueado o fuera del horizonte de cupos)");
        }

        // Reprogramación: devuelve el cupo de la franja anterior antes de tomar la nueva. Como todo corre
        // en una sola transacción, si la toma del nuevo cupo falla, esta devolución se revierte.
        if ($current !== null) {
            $this->slotReserver->release(
                $municipalityId,
                $this->slotFrom($current['date'], $current['window_start'], $current['window_end']),
            );
        }

        if (!$this->slotReserver->reserve($municipalityId, $requestedSlot)) {
            throw PromiseException::slotTaken('la franja ya no tiene cupo disponible: elegí otra franja o fecha');
        }

        $this->orders->assignSlot($command->shipmentId, $command->date, $command->windowStart, $command->windowEnd);
    }

    /**
     * @param array{date: string, window_start: string, window_end: string}|null $current
     */
    private function isSameSlot(?array $current, ReserveMunicipalitySlot $command): bool
    {
        return $current !== null
            && $current['date'] === $command->date
            && $current['window_start'] === $command->windowStart
            && $current['window_end'] === $command->windowEnd;
    }

    /**
     * Construye el slot desde primitivos. La franja viaja a capacity como la etiqueta 'HH:MM-HH:MM'
     * (el formato de TimeSlot de capacity).
     */
    private function slotFrom(string $date, string $windowStart, string $windowEnd): DeliverySlot
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === false) {
            throw PromiseException::because("fecha inválida (se esperaba Y-m-d): {$date}");
        }

        return DeliverySlot::of($day, "{$windowStart}-{$windowEnd}");
    }
}
