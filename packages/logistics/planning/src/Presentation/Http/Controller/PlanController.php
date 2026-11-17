<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Http\Support\ApiPagination;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Logistics\Planning\Application\Command\DeletePlan\DeletePlan;
use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\Command\RemoveStopFromPlan\RemoveStopFromPlan;
use Logistics\Planning\Application\Query\GetPlan\GetPlan;
use Logistics\Planning\Application\Query\ListPlansByDate\ListPlansByDate;
use Logistics\Planning\Application\Query\ListTripCandidates\ListTripCandidates;
use Logistics\Planning\Application\ReadModel\PlannedStopView;
use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Application\ReadModel\TripCandidatesView;
use Logistics\Planning\Application\ReadModel\TripSequenceSuggestionView;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Presentation\Http\Request\AssignShipmentRequest;
use Logistics\Planning\Presentation\Http\Request\GeneratePlanProposalRequest;
use Logistics\Planning\Presentation\Http\Request\ReorderStopsRequest;
use Logistics\Planning\Presentation\Http\Request\SuggestTripSequenceRequest;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;

/**
 * Puerta HTTP de planificación para la app interna: ver el plan del día, generar la propuesta,
 * mover envíos entre camiones, reordenar la secuencia, publicar y consultar la ETA.
 *
 * Agrupado por recurso (el plan). Cada acción traduce la petición a un comando o consulta y la
 * despacha por el bus correspondiente; nunca toca Infrastructure ni Domain directo. El refresco tras
 * un comando se hace releyendo con la consulta GetPlan, para devolver siempre la forma de lectura.
 *
 * Armado de viajes (asistente fecha → camión → pedidos): candidatos por cercanía, orden sugerido, crear el
 * plan respetando el orden recibido, quitar una parada y borrar el plan entero.
 *
 * Un plan PUBLICADO es de solo lectura: sumar, quitar o reordenar paradas, borrarlo (y volver a publicarlo)
 * responde 409 con el motivo; para cambiar una hoja de ruta ya publicada existe el flujo de ejecución.
 */
final class PlanController extends PlanningController
{
    private const STATUS_PUBLISHED = 'PUBLISHED';

    /** Tope de pedidos ya elegidos que acepta la consulta de candidatos. */
    private const MAX_SELECTED = 500;

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * GET /plans?date=YYYY-MM-DD — planes de reparto de un día (uno por camión).
     *
     * Aditivo (auditoría app #20): rango `date_from`/`date_to` (máx. 31 días, en lugar de `date`), filtros
     * `status` y `vehicle_id`, y paginación estándar opt-in (`page`/`per_page` o `cursor`, ver ApiPagination).
     * Sin parámetros de página la respuesta es la histórica: lista completa + meta {date, count}.
     *
     * Filtros server-side (listado de planes de la app): `status` acepta UNO o VARIOS (CSV `DRAFT,PUBLISHED`
     * o repetido `status[]=`), y `q` es texto libre contra placa / chofer / nodo de origen. Cada plan trae
     * además `execution_status` (NOT_STARTED|IN_PROGRESS|COMPLETED|null) de su ruta del día.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var array{date?: string|null, date_from?: string|null, date_to?: string|null, vehicle_id?: string|null, q?: string|null} $validated */
        $validated = $request->validate([
            'date' => ['required_without:date_from', 'nullable', 'string', 'date_format:Y-m-d'],
            'date_from' => ['required_without:date', 'required_with:date_to', 'nullable', 'string', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'vehicle_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        // `status` se acepta como CSV o repetido (uno o varios estados), así que se valida y normaliza aparte.
        $statuses = $this->parseStatuses($request->input('status'));

        // `date` (día suelto) tiene prioridad por compatibilidad; si no viene, manda el rango.
        $date = $validated['date'] ?? null;
        $from = $date ?? (string) ($validated['date_from'] ?? '');
        $to = $date !== null ? null : ($validated['date_to'] ?? null);

        if ($to !== null && $this->spanDays($from, $to) > ListPlansByDate::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'date_to' => ['The date range may not exceed '.ListPlansByDate::MAX_RANGE_DAYS.' days.'],
            ]);
        }

        $rangeMeta = ['date' => $from] + ($to !== null ? ['date_to' => $to] : []);
        $paginate = ApiPagination::requested($request);

        $result = $this->queryBus->ask(new ListPlansByDate(
            planDate: $from,
            dateTo: $to,
            statuses: $statuses,
            search: isset($validated['q']) && $validated['q'] !== '' ? $validated['q'] : null,
            vehicleId: $validated['vehicle_id'] ?? null,
            limit: $paginate ? ApiPagination::limit($request) : null,
            offset: $paginate ? ApiPagination::offset($request) : 0,
        ));

        if ($result instanceof Page) {
            /** @var Page<PlanView> $result */
            $items = array_map(static fn (PlanView $plan): array => $plan->toArray(), $result->items);
            $envelope = ApiPagination::envelope($items, $result->total, $request);

            return $this->collection($envelope['data'], $rangeMeta + $envelope['meta']);
        }

        /** @var list<PlanView> $plans */
        $plans = $result;

        return $this->collection(
            array_map(static fn (PlanView $plan): array => $plan->toArray(), $plans),
            $rangeMeta + ['count' => count($plans)],
        );
    }

    /**
     * Normaliza el filtro `status` (CSV `DRAFT,PUBLISHED` o repetido `status[]=`) a una lista de estados
     * válidos, sin duplicados. Vacío = sin filtro. Un estado desconocido responde 422.
     *
     * @return list<string>
     */
    private function parseStatuses(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);

        $out = [];
        foreach ($values as $value) {
            $status = strtoupper(trim((string) $value));
            if ($status === '') {
                continue;
            }
            if (!in_array($status, ListPlansByDate::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['The selected status is invalid: '.$status.'.'],
                ]);
            }
            $out[$status] = true;
        }

        return array_keys($out);
    }

    /**
     * Días del rango inclusive (ambas fechas ya validadas como Y-m-d).
     */
    private function spanDays(string $from, string $to): int
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);

        if ($start === false || $end === false) {
            return 0;
        }

        return (int) $start->diff($end)->days + 1;
    }

    /**
     * GET /plans/{planId} — un plan con su secuencia, ETA y plan de carga.
     */
    public function show(string $planId): JsonResponse
    {
        $plan = $this->findPlan($planId);

        if ($plan === null) {
            return $this->notFound("plan {$planId} not found");
        }

        return $this->resource($plan->toArray());
    }

    /**
     * POST /plans — crea el plan (viaje) de un vehículo para un día con las paradas dadas.
     *
     * `latitude` / `longitude` de cada parada son opcionales (se toman del envío). Por defecto se respeta el
     * orden recibido; con `optimize: true` el motor de rutas reordena.
     *
     * Un camión tiene UN plan por día: si ya existe uno para ese camión y ese día se responde 409 con el
     * motivo (la app suma los pedidos a ese plan con POST /plans/{planId}/stops; su id viaja en `plan_id`).
     *
     * El comando es void (el id lo genera el dominio), así que tras despacharlo releemos los planes del
     * día y devolvemos el del vehículo recién planificado.
     */
    public function store(GeneratePlanProposalRequest $request): JsonResponse
    {
        $command = $request->toCommand();

        $existing = $this->planOf($command->planDate, $command->vehicleId);
        if ($existing !== null) {
            return ProblemDetails::fromCode(
                ErrorCode::Conflict,
                'Ese camión ya tiene un plan para ese día: sume los pedidos a ese plan o elija otro camión.',
            )->withExtension('plan_id', $existing->id)->toResponse($request);
        }

        $this->commandBus->dispatch($command);

        $plan = $this->planOf($command->planDate, $command->vehicleId);
        if ($plan !== null) {
            return $this->resource($plan->toArray(), 201);
        }

        // No debería ocurrir: el plan acaba de persistirse. Si pasa, se informa como aceptado sin cuerpo.
        return new JsonResponse([
            'data' => ['plan_date' => $command->planDate, 'vehicle_id' => $command->vehicleId],
        ], 202);
    }

    /**
     * GET /plans/candidates — pedidos candidatos para armar el viaje de un camión, por cercanía.
     *
     * Parámetros: `date` (día del viaje), `vehicle_id` (su base y su capacidad), `selected` (lo ya elegido,
     * en orden: `selected=a,b` o `selected[]=a&selected[]=b`), `q` (buscar por pedido o cliente), `depart_at`
     * (opcional) y paginación estándar opt-in (`page`/`per_page` o `cursor`). El alcance por ciudad sale del
     * usuario autenticado, nunca de un parámetro.
     */
    public function candidates(Request $request): JsonResponse
    {
        /** @var array{date: string, vehicle_id: string, q?: string|null, depart_at?: string|null} $validated */
        $validated = $request->validate([
            'date' => ['required', 'string', 'date_format:Y-m-d'],
            'vehicle_id' => ['required', 'string', 'uuid'],
            'q' => ['nullable', 'string', 'max:120'],
            'depart_at' => ['nullable', 'string', 'date'],
        ]);

        $paginate = ApiPagination::requested($request);

        /** @var TripCandidatesView|null $view */
        $view = $this->queryBus->ask(new ListTripCandidates(
            date: $validated['date'],
            vehicleId: $validated['vehicle_id'],
            selectedShipmentIds: $this->selectedShipmentIds($request),
            search: $validated['q'] ?? null,
            departAt: $validated['depart_at'] ?? null,
            limit: $paginate ? ApiPagination::limit($request) : null,
            offset: $paginate ? ApiPagination::offset($request) : 0,
            scope: ScopeActorResolver::fromUser($request->user()),
        ));

        if ($view === null) {
            throw ValidationException::withMessages(['vehicle_id' => ['El camión indicado no existe.']]);
        }

        $items = $view->itemsToArray();
        $meta = $paginate
            ? ApiPagination::envelope($items, $view->total, $request)['meta']
            : ['count' => count($items)];

        return $this->collection($items, $meta + [
            'date' => $validated['date'],
            'anchor' => $view->anchor,
            'capacity' => $view->capacity,
        ]);
    }

    /**
     * POST /plans/suggest-sequence — orden de reparto que recomienda el backend para un viaje (camino más
     * corto respetando la hora elegida por cada cliente), con ETA por parada y avisos. No guarda nada.
     */
    public function suggestSequence(SuggestTripSequenceRequest $request): JsonResponse
    {
        /** @var TripSequenceSuggestionView|null $view */
        $view = $this->queryBus->ask($request->toQuery());

        if ($view === null) {
            throw ValidationException::withMessages(['vehicle_id' => ['El camión indicado no existe.']]);
        }

        return $this->resource($view->toArray());
    }

    /**
     * POST /plans/{planId}/stops — ajuste manual: agrega (o mueve) un envío a este camión.
     */
    public function assignShipment(AssignShipmentRequest $request, string $planId): JsonResponse
    {
        $rejection = $this->rejectIfNotEditable($request, $planId, 'sumar pedidos');
        if ($rejection !== null) {
            return $rejection;
        }

        $this->commandBus->dispatch($request->toCommand($planId));

        return $this->refreshed($planId);
    }

    /**
     * DELETE /plans/{planId}/stops/{shipmentId} — quita una parada de un plan SIN publicar.
     *
     * 404 si el plan no existe o si ese pedido no es una parada del plan; 409 si el plan ya está publicado.
     */
    public function removeStop(Request $request, string $planId, string $shipmentId): JsonResponse
    {
        $rejection = $this->rejectIfNotEditable($request, $planId, 'quitar paradas');
        if ($rejection !== null) {
            return $rejection;
        }

        // Se despacha con el id tal como está guardado en la parada (la comparación ignora mayúsculas).
        $target = strtolower(trim($shipmentId));
        $stored = null;
        $plan = $this->findPlan($planId);
        foreach ($plan === null ? [] : $plan->stops as $stop) {
            if (strtolower($stop->shipmentId) === $target) {
                $stored = $stop->shipmentId;
            }
        }

        if ($stored === null) {
            return ProblemDetails::fromCode(ErrorCode::NotFound, 'Ese pedido no es una parada de este plan.')
                ->toResponse($request);
        }

        $this->commandBus->dispatch(new RemoveStopFromPlan($planId, $stored));

        return $this->refreshed($planId);
    }

    /**
     * DELETE /plans/{planId} — borra un plan SIN publicar con todas sus paradas; sus pedidos quedan libres
     * para otro viaje. Responde 204 sin cuerpo.
     *
     * 404 si el plan no existe (también si ya se había borrado); 409 si el plan ya está publicado.
     */
    public function destroy(Request $request, string $planId): JsonResponse | Response
    {
        $rejection = $this->rejectIfNotEditable($request, $planId, 'borrar');
        if ($rejection !== null) {
            return $rejection;
        }

        try {
            $this->commandBus->dispatch(new DeletePlan($planId));
        } catch (PlanningException $exception) {
            // Solo llega acá si el plan cambió entre la guarda y el comando (otro usuario lo publicó o lo
            // borró en ese instante): el agregado lo rechaza y se informa como conflicto, con su motivo.
            return ProblemDetails::fromCode(ErrorCode::Conflict, $exception->getMessage())->toResponse($request);
        }

        return new Response('', 204);
    }

    /**
     * PUT /plans/{planId}/sequence — reordena las paradas del plan al orden indicado.
     */
    public function reorderStops(ReorderStopsRequest $request, string $planId): JsonResponse
    {
        $rejection = $this->rejectIfNotEditable($request, $planId, 'reordenar las paradas');
        if ($rejection !== null) {
            return $rejection;
        }

        $this->commandBus->dispatch($request->toCommand($planId));

        return $this->refreshed($planId);
    }

    /**
     * POST /plans/{planId}/publish — confirma el plan y lo deja disponible para la ejecución.
     */
    public function publish(Request $request, string $planId): JsonResponse
    {
        $plan = $this->findPlan($planId);
        if ($plan !== null && strtoupper($plan->status) === self::STATUS_PUBLISHED) {
            return ProblemDetails::fromCode(ErrorCode::Conflict, 'El plan ya está publicado.')->toResponse($request);
        }

        $this->commandBus->dispatch(new PublishPlan($planId));

        return $this->refreshed($planId);
    }

    /**
     * GET /plans/{planId}/eta — ETA por parada del plan (instante y minutos desde la salida).
     */
    public function eta(string $planId): JsonResponse
    {
        $plan = $this->findPlan($planId);

        if ($plan === null) {
            return $this->notFound("plan {$planId} not found");
        }

        // Cada ETA lleva los datos legibles del envío para que la app no muestre identificadores internos.
        $etas = array_map(static fn (PlannedStopView $stop): array => [
            'shipment_id' => $stop->shipmentId,
            'sequence' => $stop->sequence,
            'eta_at' => $stop->etaAt,
            'eta_minutes' => $stop->etaMinutes,
            ...$stop->readableFields(),
        ], $plan->stops);

        return $this->resource([
            'plan_id' => $plan->id,
            'status' => $plan->status,
            'stops' => $etas,
        ]);
    }

    /**
     * Guarda de edición: 404 si el plan no existe y 409 si ya está publicado (una hoja de ruta publicada es
     * de solo lectura). Devuelve null cuando el plan se puede editar.
     */
    private function rejectIfNotEditable(Request $request, string $planId, string $action): ?JsonResponse
    {
        $plan = $this->findPlan($planId);

        if ($plan === null) {
            return ProblemDetails::fromCode(ErrorCode::NotFound, 'El plan indicado no existe.')->toResponse($request);
        }

        if (strtoupper($plan->status) === self::STATUS_PUBLISHED) {
            return ProblemDetails::fromCode(
                ErrorCode::Conflict,
                "El plan ya está publicado: no se puede {$action}. Para cambiar un viaje publicado use la asignación de vehículo o quite la parada de la ruta.",
            )->toResponse($request);
        }

        return null;
    }

    /**
     * Plan del vehículo en ese día, o null si no tiene (un camión tiene un plan por día).
     */
    private function planOf(string $planDate, string $vehicleId): ?PlanView
    {
        /** @var list<PlanView> $plans */
        $plans = $this->queryBus->ask(new ListPlansByDate($planDate));

        foreach ($plans as $plan) {
            if (strtolower($plan->vehicleId) === strtolower($vehicleId)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Pedidos ya elegidos (`selected`), en orden: admite la lista separada por comas (`selected=a,b`) y la
     * forma repetida (`selected[]=a&selected[]=b`). Cada valor debe ser un UUID.
     *
     * @return list<string>
     */
    private function selectedShipmentIds(Request $request): array
    {
        $raw = $request->query('selected');
        $values = is_array($raw) ? $raw : (is_string($raw) ? explode(',', $raw) : []);

        $ids = [];
        foreach ($values as $value) {
            $id = is_string($value) ? trim($value) : '';
            if ($id === '') {
                continue;
            }
            if (!Str::isUuid($id)) {
                throw ValidationException::withMessages(['selected' => ['Cada pedido elegido debe ser un identificador válido.']]);
            }
            $ids[strtolower($id)] = true;
        }

        if (count($ids) > self::MAX_SELECTED) {
            throw ValidationException::withMessages(['selected' => ['Demasiados pedidos elegidos para un solo viaje.']]);
        }

        return array_map(strval(...), array_keys($ids));
    }

    /**
     * Relee el plan y lo devuelve, o 404 si desapareció. Uso común tras un comando.
     */
    private function refreshed(string $planId): JsonResponse
    {
        $plan = $this->findPlan($planId);

        if ($plan === null) {
            return $this->notFound("plan {$planId} not found");
        }

        return $this->resource($plan->toArray());
    }

    private function findPlan(string $planId): ?PlanView
    {
        /** @var PlanView|null $plan */
        $plan = $this->queryBus->ask(new GetPlan($planId));

        return $plan;
    }
}
