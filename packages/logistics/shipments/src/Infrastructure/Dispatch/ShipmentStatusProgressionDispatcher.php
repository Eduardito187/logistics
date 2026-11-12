<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Dispatch;

use Illuminate\Database\ConnectionInterface;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\AdvanceShipmentStatus\AdvanceShipmentStatus;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reactor planificación/ejecución → envíos: hace AVANZAR `shipments.status` a partir de los hechos de
 * otros contextos, que antes nunca lo movían (cada envío quedaba en RECEIVED/PLANNED para siempre).
 *
 * Tabla de avance (monótono: nunca retrocede; idempotente: reentregas y hechos atrasados son no-op):
 *
 *  - planning.plan_published          → PLANNED     (cada parada del plan publicado)
 *  - execution.driver_route_assembled → PLANNED     (cada parada de la ruta armada del camión)
 *  - execution.route_stop_attached    → PLANNED     (asignación manual del pedido a un camión)
 *  - execution.shipment_prepared      → IN_EXECUTION
 *  - execution.shipment_loaded        → IN_EXECUTION
 *  - execution.shipment_departed      → IN_EXECUTION
 *  - execution.delivery_failed        → IN_EXECUTION (como mínimo; la reprogramación/anulación decide después)
 *  - execution.shipment_delivered     → DELIVERED
 *
 * Por qué PLANNED solo al PUBLICAR (y no al proponer): el auto-planificador re-arma los planes PROPUESTOS
 * del día con los envíos RECEIVED; si una propuesta ya los marcara PLANNED, una segunda corrida los dejaría
 * fuera del plan rehecho y quedarían huérfanos. Un plan publicado, en cambio, no se re-planifica encima, y
 * marcar sus envíos evita que otra corrida los vuelva a meter en otro camión.
 *
 * Es un adaptador de INTEGRACIÓN (Infrastructure): cambia el estado SOLO por el Command de Application
 * (AdvanceShipmentStatus → Shipment::progressTo), nunca con UPDATE directo. Lee las paradas de plan/ruta
 * en solo lectura (tablas de planning/execution) para los hechos que no traen el id del envío.
 *
 * NUNCA lanza: va ÚLTIMO en el abanico de cada evento y el FanOutMessageDispatcher corta a los siguientes
 * si uno lanza; además, un envío desconocido o un error puntual no debe mandar el mensaje a dead-letter.
 * Cada fallo se registra en el log y se sigue; `shipments:sync-status` repara lo que haya quedado atrás.
 */
final readonly class ShipmentStatusProgressionDispatcher implements MessageDispatcher
{
    /** Plan publicado: sus paradas pasan a PLANNED. */
    public const PLAN_PUBLISHED = 'planning.plan_published';

    /** Ruta del camión armada: sus paradas pasan a PLANNED. */
    public const ROUTE_ASSEMBLED = 'execution.driver_route_assembled';

    /** Hechos que traen el `shipment_id` en el payload, con el estado al que avanzan. */
    private const SHIPMENT_EVENTS = [
        'execution.route_stop_attached' => ShipmentStatus::Planned,
        'execution.shipment_prepared' => ShipmentStatus::InExecution,
        'execution.shipment_loaded' => ShipmentStatus::InExecution,
        'execution.shipment_departed' => ShipmentStatus::InExecution,
        'execution.delivery_failed' => ShipmentStatus::InExecution,
        'execution.shipment_delivered' => ShipmentStatus::Delivered,
    ];

    public function __construct(
        private CommandBus $commandBus,
        private ConnectionInterface $connection,
        private LoggerInterface $logger,
    ) {}

    /**
     * Eventos que atiende este reactor (para verificar su registro en el ruteo del outbox).
     *
     * @return list<string>
     */
    public static function handledEvents(): array
    {
        return [...array_keys(self::SHIPMENT_EVENTS), self::PLAN_PUBLISHED, self::ROUTE_ASSEMBLED];
    }

    public function dispatch(OutboxMessage $message): void
    {
        try {
            $this->react($message);
        } catch (Throwable $error) {
            // Red de seguridad: este reactor jamás corta el abanico ni manda el mensaje a dead-letter.
            $this->logger->warning('shipments: no se pudo avanzar el estado desde un hecho', [
                'event' => $message->eventName(),
                'event_id' => $message->eventId()->value,
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function react(OutboxMessage $message): void
    {
        $event = $message->eventName();
        $payload = $message->payload();

        if (isset(self::SHIPMENT_EVENTS[$event])) {
            $shipmentId = $payload['shipment_id'] ?? null;
            if (is_string($shipmentId) && $shipmentId !== '') {
                $this->advance($shipmentId, self::SHIPMENT_EVENTS[$event], $event);
            }

            return;
        }

        if ($event === self::PLAN_PUBLISHED) {
            $planId = self::idFrom($payload['plan_id'] ?? null) ?? $message->aggregateId();
            foreach ($this->shipmentIdsOf('planning_plan_stops', 'plan_id', $planId) as $shipmentId) {
                $this->advance($shipmentId, ShipmentStatus::Planned, $event);
            }

            return;
        }

        if ($event === self::ROUTE_ASSEMBLED) {
            $routeId = self::idFrom($payload['route_id'] ?? null) ?? $message->aggregateId();
            foreach ($this->shipmentIdsOf('execution_driver_route_stops', 'route_id', $routeId) as $shipmentId) {
                $this->advance($shipmentId, ShipmentStatus::Planned, $event);
            }
        }
    }

    /**
     * Avanza UN envío. Cada envío va aislado: un id desconocido o un fallo puntual se registra y no
     * impide avanzar a las demás paradas del mismo plan/ruta.
     */
    private function advance(string $shipmentId, ShipmentStatus $target, string $trigger): void
    {
        try {
            $this->commandBus->dispatch(new AdvanceShipmentStatus($shipmentId, $target->value, $trigger));
        } catch (ShipmentsException $unknown) {
            // Caso esperable (p. ej. envío de otra base o ya purgado): se informa y se sigue.
            $this->logger->info('shipments: hecho ignorado para un envío desconocido', [
                'event' => $trigger,
                'shipment_id' => $shipmentId,
                'reason' => $unknown->getMessage(),
            ]);
        } catch (Throwable $error) {
            $this->logger->warning('shipments: falló el avance de estado de un envío', [
                'event' => $trigger,
                'shipment_id' => $shipmentId,
                'target' => $target->value,
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * Ids de envío de las paradas de un plan o ruta (lectura cruzada de solo lectura, sin su dominio).
     *
     * @return list<string>
     */
    private function shipmentIdsOf(string $table, string $column, string $ownerId): array
    {
        // Columna uuid: un id malformado haría fallar la sentencia en PostgreSQL (y abortaría una
        // transacción envolvente). Se descarta antes de consultar.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ownerId) !== 1) {
            return [];
        }

        $ids = [];
        foreach ($this->connection->table($table)->where($column, $ownerId)->orderBy('sequence')->pluck('shipment_id') as $id) {
            if (is_scalar($id) && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function idFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
