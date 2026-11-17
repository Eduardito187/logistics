<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Access\Application\Command\RecordAuditEntry\RecordAuditEntry;
use Logistics\Capacity\Application\Query\CheckDispatchDay\CheckDispatchDayQuery;
use Logistics\Capacity\Application\Query\CheckDispatchSlot\CheckDispatchSlotQuery;
use Logistics\Capacity\Application\ReadModel\DispatchDayAvailabilityView;
use Logistics\Capacity\Application\ReadModel\DispatchSlotAvailabilityView;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Shipments\Application\Command\RescheduleShipmentDelivery\RescheduleShipmentDelivery;
use Logistics\Shipments\Application\Query\CheckShipmentScope\CheckShipmentScope;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\ReadModel\ShipmentScopeDecision;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;
use Throwable;

/**
 * Reprogramación de pedidos desde la app: uno, varios, todos los de un camión o todos los de una ciudad.
 *
 *  - POST /app/shipments/reschedule/preview   muestra a qué pedidos alcanza, SIN cambiar nada
 *  - POST /app/shipments/reschedule           aplica (admin; el planificador solo pedidos puntuales)
 *
 * QUIÉN puede qué (mismo criterio en la vista previa y al aplicar):
 *  - pedidos puntuales (`shipment_ids`): admin y planificador (y el supervisor en la vista previa). Cada
 *    envío debe estar dentro del alcance por rol del usuario (regla única de `CheckShipmentScope`): con un
 *    envío de otra ciudad responde 403 `out_of_scope_city` y no se reprograma ninguno;
 *  - masivo (`vehicle_id` o `city`): APLICAR es solo de admin; la vista previa, de admin y supervisor. El
 *    planificador recibe 403 `insufficient_role` en las dos.
 *
 * Mismo body en los dos. El alcance (`scope`) es UNO de: `shipment_ids`, `vehicle_id`, o `city` (con
 * `route_date` opcional para acotar al día comprometido actual). Los pedidos cerrados (entregados, fallidos,
 * anulados) nunca se reprograman: se informan como omitidos. Una fecha bloqueada en el calendario de
 * despacho se rechaza. Cada pedido se reprograma con el comando de dominio de siempre (graba el hecho y el
 * seguimiento pasa a «reprogramada»); un fallo en uno no corta el resto. Queda auditado.
 *
 * El alcance masivo (camión / ciudad) tiene un tope de pedidos por operación: si lo supera, se rechaza para
 * que se acote el alcance en vez de mover de más.
 */
final readonly class RescheduleShipmentsController
{
    /** Tope de pedidos por operación (protege de un alcance demasiado amplio). */
    private const MAX_SHIPMENTS = 500;

    private const CLOSED = ['DELIVERED', 'FAILED', 'CANCELLED'];

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $problem = $this->problemFor($request, $data, applying: false);
        if ($problem !== null) {
            return $problem;
        }

        [$targets, $skipped] = $this->targets($data);
        if (count($targets) > self::MAX_SHIPMENTS) {
            return $this->tooMany(count($targets));
        }

        return new JsonResponse(['data' => $this->summary($data, $targets, $skipped) + ['applied' => false]]);
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $problem = $this->problemFor($request, $data, applying: true);
        if ($problem !== null) {
            return $problem;
        }

        [$targets, $skipped] = $this->targets($data);
        if (count($targets) > self::MAX_SHIPMENTS) {
            return $this->tooMany(count($targets));
        }

        $rescheduled = [];
        $failed = [];
        foreach ($targets as $shipment) {
            try {
                $this->commandBus->dispatch(new RescheduleShipmentDelivery(
                    $shipment->id,
                    $data['new_date'],
                    $data['window_start'],
                    $data['window_end'],
                    $data['reason'] ?? null,
                ));
                $rescheduled[] = $shipment;
            } catch (Throwable $e) {
                $failed[] = ['shipment_id' => $shipment->id, 'external_id' => $shipment->externalId, 'reason' => $e->getMessage()];
            }
        }

        if ((bool) ($data['notify_customer'] ?? false)) {
            foreach ($rescheduled as $shipment) {
                $this->notify($shipment);
            }
        }

        $this->audit($request, $data, count($rescheduled), count($failed));

        return new JsonResponse(['data' => $this->summary($data, $rescheduled, $skipped) + [
            'applied' => true,
            'failed' => $failed,
        ]]);
    }

    /**
     * @return array{scope: array{shipment_ids?: list<string>, vehicle_id?: string|null, city?: string|null, route_date?: string|null}, new_date: string, window_start: string, window_end: string, reason?: string|null, notify_customer?: bool}
     */
    private function validated(Request $request): array
    {
        /** @var array{scope: array{shipment_ids?: list<string>, vehicle_id?: string|null, city?: string|null, route_date?: string|null}, new_date: string, window_start: string, window_end: string, reason?: string|null, notify_customer?: bool} */
        return $request->validate([
            'scope' => ['required', 'array'],
            'scope.shipment_ids' => ['sometimes', 'array', 'min:1', 'max:'.self::MAX_SHIPMENTS],
            'scope.shipment_ids.*' => ['required', 'uuid'],
            'scope.vehicle_id' => ['sometimes', 'nullable', 'uuid'],
            'scope.city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'scope.route_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'new_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i', 'after:window_start'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notify_customer' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * Rechazos previos a tocar nada: alcance ambiguo o vacío, alcance que el rol no puede usar, o fecha
     * bloqueada en el calendario.
     *
     * @param array{scope: array<string, mixed>, new_date: string, window_start: string, window_end: string} $data
     */
    private function problemFor(Request $request, array $data, bool $applying): ?JsonResponse
    {
        $scope = $data['scope'];
        $given = 0;
        $given += isset($scope['shipment_ids']) && $scope['shipment_ids'] !== [] ? 1 : 0;
        $given += isset($scope['vehicle_id']) && $scope['vehicle_id'] !== '' ? 1 : 0;
        $given += isset($scope['city']) && trim((string) $scope['city']) !== '' ? 1 : 0;

        if ($given !== 1) {
            return $this->problem('invalid_scope', 'Send exactly one scope: shipment_ids, vehicle_id or city.', 422);
        }

        $denied = $this->accessProblem($request, $scope, $applying);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $day = $this->queryBus->ask(new CheckDispatchDayQuery($data['new_date']));
        } catch (Throwable) {
            $day = null;
        }

        if ($day instanceof DispatchDayAvailabilityView && (bool) ($day->toArray()['blocked'] ?? false)) {
            $reason = (string) ($day->toArray()['reason'] ?? '');

            return $this->problem(
                'date_blocked',
                'The new date is blocked in the delivery calendar'.($reason !== '' ? ': '.$reason : '.'),
                422,
            );
        }

        // Una FRANJA bloqueada tampoco admite reprogramación (alcance global: la reprogramación masiva no
        // resuelve municipio). La franja se arma con las ventanas validadas (`H:i-H:i`).
        try {
            $slot = $this->queryBus->ask(new CheckDispatchSlotQuery(
                $data['new_date'],
                $data['window_start'].'-'.$data['window_end'],
                null,
            ));
        } catch (Throwable) {
            $slot = null;
        }

        if ($slot instanceof DispatchSlotAvailabilityView && $slot->blocked) {
            $reason = (string) $slot->reason;

            return $this->problem(
                'slot_blocked',
                'The new time slot is blocked in the delivery calendar'.($reason !== '' ? ': '.$reason : '.'),
                422,
            );
        }

        return null;
    }

    /**
     * Autorización por alcance. Masivo (camión / ciudad): solo admin al aplicar; admin y supervisor en la
     * vista previa. Pedidos puntuales: cada envío que exista debe estar dentro del alcance por rol del
     * usuario (su ciudad para el planificador); admin y supervisor no tienen restricción.
     *
     * @param array<string, mixed> $scope
     */
    private function accessProblem(Request $request, array $scope, bool $applying): ?JsonResponse
    {
        $user = $request->user();
        $shipmentIds = [];
        foreach (is_array($scope['shipment_ids'] ?? null) ? $scope['shipment_ids'] : [] as $id) {
            if (is_string($id) && $id !== '') {
                $shipmentIds[] = $id;
            }
        }

        if ($shipmentIds === []) {
            $allowed = $applying ? [Role::Admin] : [Role::Admin, Role::Supervisor];
            if ($user instanceof User && $user->hasAnyRole(...$allowed)) {
                return null;
            }

            return $this->problem(
                'insufficient_role',
                'Rescheduling by vehicle or by city is reserved for administrators. Send the orders one by one or as a list (shipment_ids).',
                403,
            );
        }

        /** @var ShipmentScopeDecision $decision */
        $decision = $this->queryBus->ask(new CheckShipmentScope(ScopeActorResolver::fromUser($user), shipmentIds: $shipmentIds));
        if ($decision->allowed) {
            return null;
        }

        return $this->problem($decision->code ?? 'forbidden', (string) $decision->message, 403);
    }

    /**
     * Pedidos alcanzados, separados en reprogramables y omitidos (cerrados).
     *
     * @param  array{scope: array<string, mixed>}                           $data
     * @return array{0: list<ShipmentView>, 1: list<array<string, string>>}
     */
    private function targets(array $data): array
    {
        $scope = $data['scope'];
        $views = [];

        if (isset($scope['shipment_ids']) && is_array($scope['shipment_ids']) && $scope['shipment_ids'] !== []) {
            foreach ($scope['shipment_ids'] as $id) {
                $view = $this->queryBus->ask(new FindShipment((string) $id));
                if ($view instanceof ShipmentView) {
                    $views[] = $view;
                }
            }
        } else {
            $routeDate = isset($scope['route_date']) && $scope['route_date'] !== '' ? (string) $scope['route_date'] : null;
            // Con límite la consulta devuelve una página (ítems + total); sin límite, la lista.
            $result = $this->queryBus->ask(new ListShipments(
                limit: self::MAX_SHIPMENTS + 1,
                offset: 0,
                deliveryDateFrom: $routeDate,
                deliveryDateTo: $routeDate,
                city: isset($scope['city']) && trim((string) $scope['city']) !== '' ? trim((string) $scope['city']) : null,
                vehicleId: isset($scope['vehicle_id']) && $scope['vehicle_id'] !== '' ? (string) $scope['vehicle_id'] : null,
            ));
            /** @var list<ShipmentView> $views */
            $views = $result instanceof Page ? $result->items : (is_array($result) ? $result : []);
        }

        $targets = [];
        $skipped = [];
        foreach ($views as $view) {
            if (in_array(strtoupper($view->status), self::CLOSED, true)) {
                $skipped[] = ['shipment_id' => $view->id, 'external_id' => $view->externalId, 'reason' => 'closed:'.strtolower($view->status)];

                continue;
            }
            $targets[] = $view;
        }

        return [$targets, $skipped];
    }

    /**
     * @param  array{new_date: string, window_start: string, window_end: string} $data
     * @param  list<ShipmentView>                                                $shipments
     * @param  list<array<string, string>>                                       $skipped
     * @return array<string, mixed>
     */
    private function summary(array $data, array $shipments, array $skipped): array
    {
        return [
            'new_date' => $data['new_date'],
            'window_start' => $data['window_start'],
            'window_end' => $data['window_end'],
            'count' => count($shipments),
            'skipped_count' => count($skipped),
            'shipments' => array_map(static fn (ShipmentView $view): array => [
                'shipment_id' => $view->id,
                'external_id' => $view->externalId,
                'customer_name' => $view->customerName,
                'status' => $view->status,
                'current_date' => $view->deliveryDate,
                'current_window_start' => $view->deliveryWindowStart,
                'current_window_end' => $view->deliveryWindowEnd,
            ], $shipments),
            'skipped' => $skipped,
        ];
    }

    /** Aviso «reprogramada» al cliente, solo si hay un contacto. Tolerante: un aviso fallido no corta el lote. */
    private function notify(ShipmentView $shipment): void
    {
        $email = $shipment->customerEmail !== null && trim($shipment->customerEmail) !== '' ? $shipment->customerEmail : null;
        $phone = $shipment->customerPhone !== null && trim($shipment->customerPhone) !== '' ? $shipment->customerPhone : null;
        if ($email === null && $phone === null) {
            return;
        }

        try {
            $this->commandBus->dispatch(new QueueNotification($shipment->id, 'RESCHEDULED', $email, $phone));
        } catch (Throwable) {
            // el pedido ya quedó reprogramado; el aviso no es condición.
        }
    }

    /**
     * @param array{scope: array<string, mixed>, new_date: string, window_start: string, window_end: string, reason?: string|null, notify_customer?: bool} $data
     */
    private function audit(Request $request, array $data, int $rescheduled, int $failed): void
    {
        $user = $request->user();
        $actorId = $user?->getAuthIdentifier();
        $label = $user !== null && is_string($user->getAttribute('name')) ? $user->getAttribute('name') : 'App';

        try {
            $this->commandBus->dispatch(new RecordAuditEntry(
                action: 'shipments.rescheduled_in_bulk',
                subjectType: 'shipment_batch',
                subjectId: null,
                actorId: is_scalar($actorId) ? (string) $actorId : null,
                actorLabel: $label,
                context: [
                    'scope' => $data['scope'],
                    'new_date' => $data['new_date'],
                    'window' => $data['window_start'].'-'.$data['window_end'],
                    'reason' => $data['reason'] ?? null,
                    'notify_customer' => (bool) ($data['notify_customer'] ?? false),
                    'rescheduled' => $rescheduled,
                    'failed' => $failed,
                ],
                ipAddress: $request->ip(),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function tooMany(int $count): JsonResponse
    {
        return $this->problem(
            'scope_too_large',
            'This scope reaches more than '.self::MAX_SHIPMENTS.' orders ('.$count.'+). Narrow it (for example with route_date).',
            422,
        );
    }

    private function problem(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'type' => rtrim((string) config('api.problem_type_base_uri', '/problems'), '/').'/'.$code,
            'title' => ucfirst(str_replace('_', ' ', $code)),
            'status' => $status,
            'code' => $code,
            'detail' => $message,
            'error' => ['code' => $code, 'message' => $message],
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}
