<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Infrastructure\CrossContext\RetiredVehicleStatuses;
use Logistics\Telemetry\Infrastructure\LiveState\FleetPlateResolver;
use Logistics\Telemetry\Infrastructure\LiveState\LiveFleetViewFactory;
use Logistics\Telemetry\Infrastructure\LiveState\LiveVehicleState;
use Logistics\Telemetry\Presentation\Broadcasting\FleetVehicleRemoved;

/**
 * Reactor outbox: cuando flota da de BAJA un vehículo (`fleet.vehicle_status_changed` con `to` = `inactive`),
 * la TELEMETRÍA lo saca de la flota en vivo:
 *
 *  1. limpia su estado caliente en Valkey (última posición, marcas de throttle, lectura pendiente) y su
 *     referencia en caché (placa, sede, marca de baja);
 *  2. difunde `fleet.removed` con `{vehicle_id}` por los canales del tema `fleet` de la ciudad del camión
 *     (+ `fleet.all`), para que la app lo quite de su lista sin volver a pedir la flota.
 *
 * La consulta `fleet/live` ya lo excluye por su cuenta (lee el estado del vehículo en flota), así que este
 * reactor solo aporta la inmediatez. Un camión NO se quita por tiempo sin reportar.
 *
 * El id del vehículo es el `aggregate_id` del mensaje (el evento de fleet solo trae `from` y `to`). Si el
 * vehículo VUELVE de una baja, se olvida la referencia en caché para que reaparezca con su próxima lectura.
 *
 * Idempotente: el outbox reentrega at-least-once; una segunda entrega vuelve a limpiar (no-op) y repite el
 * aviso, que la app aplica sin efecto si ya lo quitó. Corre en el worker del outbox, nunca en un request.
 */
final readonly class BroadcastFleetRemovedOnVehicleRetired implements MessageDispatcher
{
    /** Evento de dominio de fleet que se escucha. */
    public const string EVENT_NAME = 'fleet.vehicle_status_changed';

    public function __construct(
        private FleetDirectory $fleet,
        private LiveVehicleState $state,
        private LiveFleetViewFactory $fleetViews,
        private FleetPlateResolver $plates,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== self::EVENT_NAME) {
            return;
        }

        $vehicleId = trim($message->aggregateId());
        if ($vehicleId === '') {
            return;
        }

        $payload = $message->payload();
        $retiredNow = RetiredVehicleStatuses::includes($payload['to'] ?? null);
        $wasRetired = RetiredVehicleStatuses::includes($payload['from'] ?? null);

        if (!$retiredNow && !$wasRetired) {
            // Cambio entre estados operativos (disponible, en servicio, mantenimiento): sigue en vivo.
            return;
        }

        // En ambos sentidos (baja o vuelta de una baja) la referencia en caché quedó vieja.
        $this->fleetViews->forgetVehicle($vehicleId);
        $this->plates->forget($vehicleId);

        if ($retiredNow) {
            $this->removeFromLiveFleet($vehicleId);
        }
    }

    /** Baja efectiva: limpia el estado caliente del camión y avisa a la app con `fleet.removed`. */
    private function removeFromLiveFleet(string $vehicleId): void
    {
        $this->state->forget($vehicleId);

        // Ciudad del camión: departamento de su nodo base (sin sede conocida, solo `fleet.all`).
        $city = $this->fleet->homeBases([$vehicleId])[$vehicleId]['department'] ?? null;

        broadcast(new FleetVehicleRemoved($vehicleId, $city));
    }
}
