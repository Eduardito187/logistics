<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ReserveDeliveryDate;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchCalendar;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchGate;
use Logistics\Promise\Domain\Contract\MunicipalitySlotReserver;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Event\DeliveryWindowAssigned;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Service\CustomerLocationResolver;
use Logistics\Promise\Domain\Service\WindowSelector;
use Logistics\Promise\Domain\ValueObject\CustomerTimePreference;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\WindowCandidate;
use Logistics\Promise\Domain\ValueObject\WindowSelectionPolicy;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Orquesta la reserva por FECHA de punta a punta (Módulo 1): resuelve el municipio del envío, valida que
 * el día sea despachable, lee las franjas con cupo del municipio, deja que `WindowSelector` elija la
 * ventana (honrando la preferencia del cliente y balanceando carga), consume una entrega del cupo y fija
 * la ventana elegida en el envío. Todo dentro de la misma transacción del CommandBus: los dos efectos
 * (cupo y franja) son atómicos.
 *
 * La fecha es un compromiso (R1.1): este handler NUNCA mueve el día. Si el día no es despachable responde
 * "día no disponible" (422); si es despachable pero ninguna franja tiene cupo factible, responde "sin
 * ventana" (422) para que el canal/operaciones escale a la cola "Unslotted" (Fase 1.1).
 *
 * Idempotente por (envío, fecha): si el envío ya tiene una ventana EN ESA fecha, no reconsume cupo ni
 * reemite evento. Si tenía una ventana en OTRA fecha, primero devuelve ese cupo (reprogramación de día).
 *
 * El cruce a coverage/capacity/shipments es por puertos y por el bus; este handler es PHP puro salvo la
 * lectura de pesos de Configuración.
 *
 * @implements CommandHandler<ReserveDeliveryDate>
 */
final readonly class ReserveDeliveryDateHandler implements CommandHandler
{
    /** Origen de la ventana cuando la elige el sistema (frente a 'customer' / 'ops'). */
    private const SLOT_SOURCE_AUTO = 'auto';

    public function __construct(
        private CustomerLocationResolver $location,
        private MunicipalityDispatchGate $dispatchGate,
        private MunicipalityDispatchCalendar $calendar,
        private MunicipalitySlotReserver $slotReserver,
        private OrderDeliverySlotGateway $orders,
        private WindowSelector $selector,
        private RecordDomainEvents $recordEvents,
        private Config $config,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReserveDeliveryDate);

        $municipalityId = $this->location->resolve(
            $command->municipalityId,
            $command->latitude,
            $command->longitude,
            $command->shipmentId,
        );

        if ($municipalityId === null) {
            throw PromiseException::because('no se pudo resolver el municipio del envío: la dirección no cae en ninguna zona de cobertura');
        }

        $day = $this->parseDate($command->date);

        // Idempotencia por fecha: si el envío ya tiene una ventana ese día, nada que hacer.
        $current = $this->orders->currentSlot($command->shipmentId);
        if ($current !== null && $current['date'] === $command->date) {
            return;
        }

        // Día no despachable → "día no disponible" (422), distinto de "sin ventana". Se valida antes de
        // tocar cupo, igual que la reserva de franja del cliente.
        if (!$this->dispatchGate->isDispatchable($municipalityId, $day)) {
            throw PromiseException::dayUnavailable("el día {$command->date} no está disponible para despacho en el municipio (bloqueado o fuera del horizonte de cupos)");
        }

        $candidates = $this->candidatesFor($municipalityId, $day);
        $choice = $this->selector->select(
            $candidates,
            CustomerTimePreference::fromArray($command->preference),
            $this->policy(),
        );

        if ($choice === null) {
            throw PromiseException::noWindow("el día {$command->date} no tiene ninguna franja con cupo disponible para el municipio");
        }
        if (!$choice->hasBounds()) {
            // Sin bordes 'HH:MM' no se puede abrir la ventana en el envío; el canal debe ofrecer franjas
            // con formato 'HH:MM-HH:MM' (el de capacity). No se consume cupo.
            throw PromiseException::noWindow("la franja elegida ({$choice->timeSlot}) no tiene bordes horarios para abrir la ventana");
        }

        /** @var string $windowStart */
        $windowStart = $choice->windowStart;
        /** @var string $windowEnd */
        $windowEnd = $choice->windowEnd;

        // Reprogramación de día: devuelve el cupo del día anterior antes de tomar el nuevo. Como todo corre
        // en una transacción, si la toma del nuevo cupo falla, esta devolución se revierte.
        if ($current !== null) {
            $this->slotReserver->release(
                $municipalityId,
                DeliverySlot::of(
                    $this->parseDate($current['date']),
                    "{$current['window_start']}-{$current['window_end']}",
                ),
            );
        }

        $selectedSlot = DeliverySlot::of($day, $choice->timeSlot);
        if (!$this->slotReserver->reserve($municipalityId, $selectedSlot)) {
            // Carrera: otro request tomó la última entrega entre el cálculo y la reserva.
            throw PromiseException::slotTaken('la franja elegida se quedó sin cupo: reintentá la reserva');
        }

        $this->orders->assignSlot($command->shipmentId, $command->date, $windowStart, $windowEnd);

        $this->recordEvents->record([
            new DeliveryWindowAssigned(
                $command->shipmentId,
                $command->date,
                $choice->timeSlot,
                $windowStart,
                $windowEnd,
                self::SLOT_SOURCE_AUTO,
            ),
        ], 'promise.delivery_commitment');
    }

    /**
     * Candidatas del municipio para el día: una por franja con cupo que devuelve el calendario (que ya
     * excluye días no despachables).
     *
     * @return list<WindowCandidate>
     */
    private function candidatesFor(string $municipalityId, DateTimeImmutable $day): array
    {
        $candidates = [];
        foreach ($this->calendar->availableSlots($municipalityId, $day) as $slot) {
            $candidates[] = WindowCandidate::of($slot->timeSlot, $slot->available);
        }

        return $candidates;
    }

    /**
     * Pesos de la elección, de Configuración (sección Promise › Auto scheduling), con defaults funcionales.
     */
    private function policy(): WindowSelectionPolicy
    {
        $weights = $this->config->get('logistics-promise.auto_scheduling.weights');

        return is_array($weights) ? WindowSelectionPolicy::fromArray($weights) : WindowSelectionPolicy::default();
    }

    private function parseDate(string $date): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === false) {
            throw PromiseException::because("fecha inválida (se esperaba Y-m-d): {$date}");
        }

        return $day;
    }
}
