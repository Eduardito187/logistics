<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use App\Models\User;
use App\Support\Cockpit\Concerns\ReadsCockpitAggregates;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Planning\Application\Command\ScheduleReplan\ScheduleReplan;
use Logistics\Promise\Application\Command\ExpireDueReservations\ExpireDueReservations;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Ejecuta las propuestas APROBADAS del {@see OperationsBrief}: cada acción despacha un Command REAL al
 * CommandBus CQRS (nunca escribe un record de lectura ni toca el dominio directo) y deja constancia en
 * la bitácora de auditoría. Las propuestas sin Command real quedan como consultivas en el Brief
 * (`actionable=false`) y aquí se rechazan en vez de simularse.
 *
 * Mapa propuesta → Command(s) despachado(s) (todos existen en el repo):
 *   - 'notify_delayed_customers'      → {@see QueueNotification} (contexto notifications), uno por envío en
 *                                        riesgo, con messageType DELAYED_EN_ROUTE. El canal/cascada lo
 *                                        resuelven las reglas de notifications; aquí no se decide canal.
 *   - 'replan_late_routes'            → {@see ScheduleReplan} (contexto planning), con los envíos en riesgo,
 *                                        planDate = mañana, reason = 'control_tower_at_risk'.
 *   - 'release_expired_reservations'  → {@see ExpireDueReservations} (contexto promise), barrido en lote que
 *                                        libera el cupo de toda reserva vencida.
 *
 * La auditoría se escribe despachando {@see RecordAuditEntry} (contexto access) al MISMO CommandBus: el
 * autor sale del usuario admin recibido en approve() y la acción es una clave estable 'cockpit.<verbo>'.
 * Todo tolerante: si un contexto no está disponible, approve() devuelve ok=false con un mensaje legible,
 * nunca una excepción a la UI.
 */
final class OperationsActionService
{
    use ReadsCockpitAggregates;

    /** Tope de envíos por acción en lote, para no despachar un volumen irracional de una sola vez. */
    private const BATCH_LIMIT = 500;

    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    /**
     * Ejecuta la propuesta aprobada. Devuelve el resultado para que la UI muestre un aviso.
     *
     * @return array{ok: bool, message: string}
     */
    public function approve(string $proposalId, User $actor): array
    {
        try {
            return match ($proposalId) {
                'notify_delayed_customers' => $this->notifyDelayedCustomers($actor),
                'replan_late_routes' => $this->replanLateRoutes($actor),
                'release_expired_reservations' => $this->releaseExpiredReservations($actor),
                default => ['ok' => false, 'message' => 'Propuesta desconocida o informativa; nada que aprobar.'],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo completar la acción en este momento.'];
        }
    }

    /**
     * Encola una notificación de retraso (DELAYED_EN_ROUTE) por cada envío en riesgo.
     *
     * @return array{ok: bool, message: string}
     */
    private function notifyDelayedCustomers(User $actor): array
    {
        $shipmentIds = $this->atRiskShipmentIds();
        if ($shipmentIds === []) {
            return ['ok' => false, 'message' => 'No hay envíos en riesgo en este momento.'];
        }

        foreach ($shipmentIds as $shipmentId) {
            $this->commandBus->dispatch(new QueueNotification(
                shipmentId: $shipmentId,
                messageType: MessageType::DelayedEnRoute->value,
            ));
        }

        $count = count($shipmentIds);
        $this->audit($actor, 'cockpit.customers_notified', ['shipment_count' => $count]);

        return ['ok' => true, 'message' => "Se encoló un aviso de retraso para {$count} clientes."];
    }

    /**
     * Replanifica los envíos en riesgo para la próxima fecha (mañana).
     *
     * @return array{ok: bool, message: string}
     */
    private function replanLateRoutes(User $actor): array
    {
        $shipmentIds = $this->atRiskShipmentIds();
        if ($shipmentIds === []) {
            return ['ok' => false, 'message' => 'No hay envíos atrasados para replanificar.'];
        }

        $planDate = now()->addDay()->toDateString();

        $this->commandBus->dispatch(new ScheduleReplan(
            shipmentIds: $shipmentIds,
            planDate: $planDate,
            reason: 'control_tower_at_risk',
        ));

        $count = count($shipmentIds);
        $this->audit($actor, 'cockpit.routes_replanned', ['shipment_count' => $count, 'plan_date' => $planDate]);

        return ['ok' => true, 'message' => "Se programó replanificar {$count} envíos para {$planDate}."];
    }

    /**
     * Libera, en lote, el cupo de toda reserva de promesa vencida.
     *
     * @return array{ok: bool, message: string}
     */
    private function releaseExpiredReservations(User $actor): array
    {
        $this->commandBus->dispatch(new ExpireDueReservations);

        $this->audit($actor, 'cockpit.reservations_released', []);

        return ['ok' => true, 'message' => 'Se liberaron todas las reservas de cupo vencidas.'];
    }

    /**
     * Ids (UUID) de los envíos cuyas entregas están en riesgo (ventana vencida y aún activas). Vacío si
     * la fuente no existe.
     *
     * @return list<string>
     */
    private function atRiskShipmentIds(): array
    {
        try {
            /** @var list<string> $ids */
            $ids = $this->atRiskDeliveriesQuery()
                ->limit(self::BATCH_LIMIT)
                ->pluck('d.shipment_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            return $ids;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Deja constancia de la acción en la bitácora despachando RecordAuditEntry al CommandBus. El autor
     * sale del usuario admin recibido; el contexto son primitivos serializables.
     *
     * @param array<string, mixed> $context
     */
    private function audit(User $actor, string $action, array $context): void
    {
        try {
            $actorId = (string) $actor->getAuthIdentifier();
            $label = $actor->getAttribute('name');
            if (!is_string($label) || trim($label) === '') {
                $email = $actor->getAttribute('email');
                $label = is_string($email) && trim($email) !== '' ? $email : $actorId;
            }

            $this->commandBus->dispatch(new \Logistics\Access\Application\Command\RecordAuditEntry\RecordAuditEntry(
                action: $action,
                subjectType: 'ControlTower',
                subjectId: null,
                actorId: $actorId,
                actorLabel: $label,
                context: $context,
            ));
        } catch (\Throwable) {
            // La auditoría es best-effort: su fallo no debe revertir la acción ya despachada.
        }
    }
}
