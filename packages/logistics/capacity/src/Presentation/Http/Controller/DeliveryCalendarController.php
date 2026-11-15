<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Controller;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Capacity\Application\Command\ActivateCapacityWindow\ActivateCapacityWindowCommand;
use Logistics\Capacity\Application\Command\BlockDispatchDay\BlockDispatchDayCommand;
use Logistics\Capacity\Application\Command\UnblockDispatchDay\UnblockDispatchDayCommand;
use Logistics\Capacity\Application\Query\CheckDispatchDay\CheckDispatchDayQuery;
use Logistics\Capacity\Application\Query\GetActivationOptions\GetActivationOptionsQuery;
use Logistics\Capacity\Application\Query\ListBlackoutDays\ListBlackoutDaysQuery;
use Logistics\Capacity\Application\ReadModel\ActivationOptionsView;
use Logistics\Capacity\Application\ReadModel\BlackoutDayView;
use Logistics\Capacity\Application\ReadModel\DispatchDayAvailabilityView;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Coverage\Application\Query\ListMunicipalities;
use Logistics\Coverage\Application\ReadModel\MunicipalityView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Calendario de despacho para la app (admin / supervisor): las fechas de envío, las bloqueadas y la
 * apertura de cupos. Expone por API lo mismo que la página «Dispatch calendar» del admin web, con los mismos
 * comandos y consultas del contexto (no hay un calendario nuevo).
 *
 *  - GET    /app/delivery-calendar            días del rango con su disponibilidad + bloqueos
 *  - POST   /app/delivery-calendar/blocked    bloquea un día (feriado, cierre) para un municipio o para todos
 *  - DELETE /app/delivery-calendar/blocked    quita el bloqueo de un día
 *  - POST   /app/delivery-calendar/open       activa cupos por rango, días de la semana y franjas (asíncrono)
 *  - GET    /app/delivery-calendar/options    catálogos para armar la activación (municipios, franjas, unidades)
 *  - POST   /app/delivery-calendar/open-default  habilita una fecha o rango con los cupos por defecto (asíncrono)
 *
 * Los municipios se leen del catálogo de coverage por su lado de consulta (QueryBus → ListMunicipalities),
 * igual que la página del admin; las franjas, unidades y ventanas por defecto salen de las reglas de cupo.
 */
final readonly class DeliveryCalendarController
{
    private const MAX_RANGE_DAYS = 62;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var array{from?: string|null, to?: string|null, municipality_id?: string|null} $filters */
        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'municipality_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $from = new DateTimeImmutable($filters['from'] ?? now()->toDateString());
        $to = isset($filters['to']) ? new DateTimeImmutable($filters['to']) : $from->add(new DateInterval('P30D'));
        if ((int) $from->diff($to)->days > self::MAX_RANGE_DAYS) {
            $to = $from->add(new DateInterval('P'.self::MAX_RANGE_DAYS.'D'));
        }
        $municipalityId = $filters['municipality_id'] ?? null;

        $days = [];
        for ($day = $from; $day <= $to; $day = $day->add(new DateInterval('P1D'))) {
            $view = $this->queryBus->ask(new CheckDispatchDayQuery($day->format('Y-m-d'), $municipalityId));
            if ($view instanceof DispatchDayAvailabilityView) {
                $data = $view->toArray();
                // Un día se puede prometer si no está bloqueado y cae dentro del horizonte con cupos activos.
                $data['available'] = !($data['blocked'] ?? false) && (bool) ($data['within_horizon'] ?? false);
                $days[] = $data;
            }
        }

        /** @var list<BlackoutDayView> $blackouts */
        $blackouts = $this->queryBus->ask(new ListBlackoutDaysQuery($from->format('Y-m-d'), $to->format('Y-m-d'), $municipalityId));

        return new JsonResponse([
            'data' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'municipality_id' => $municipalityId,
                'days' => $days,
                'blocked' => array_map(static fn (BlackoutDayView $view): array => $view->toArray(), $blackouts),
            ],
        ]);
    }

    public function block(Request $request): JsonResponse
    {
        /** @var array{date: string, municipality_id?: string|null, reason: string} $data */
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'municipality_id' => ['sometimes', 'nullable', 'uuid'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->guard(function () use ($data, $request): JsonResponse {
            $this->commandBus->dispatch(new BlockDispatchDayCommand(
                date: $data['date'],
                municipalityId: $data['municipality_id'] ?? null,
                reason: trim($data['reason']),
                blockedBy: $this->actor($request),
            ));

            return new JsonResponse(['data' => ['status' => 'ok', 'date' => $data['date'], 'blocked' => true]], 201);
        });
    }

    public function unblock(Request $request): JsonResponse
    {
        /** @var array{date: string, municipality_id?: string|null} $data */
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'municipality_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return $this->guard(function () use ($data): JsonResponse {
            $this->commandBus->dispatch(new UnblockDispatchDayCommand(
                date: $data['date'],
                municipalityId: $data['municipality_id'] ?? null,
            ));

            return new JsonResponse(['data' => ['status' => 'ok', 'date' => $data['date'], 'blocked' => false]]);
        });
    }

    public function open(Request $request): JsonResponse
    {
        /** @var array{municipality_ids: list<string>, from: string, to: string, weekdays: list<int>, windows: list<array{time_slot: string, unit: string, quantity: float|int}>} $data */
        $data = $request->validate([
            'municipality_ids' => ['required', 'array', 'min:1', 'max:400'],
            'municipality_ids.*' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            // 1 = lunes … 7 = domingo.
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7'],
            'windows' => ['required', 'array', 'min:1', 'max:24'],
            'windows.*.time_slot' => ['required', 'string', 'max:32'],
            'windows.*.unit' => ['required', 'string', 'max:32'],
            'windows.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        return $this->guard(function () use ($data, $request): JsonResponse {
            $this->commandBus->dispatch(new ActivateCapacityWindowCommand(
                municipalityIds: array_values($data['municipality_ids']),
                fromDate: $data['from'],
                toDate: $data['to'],
                weekdays: array_values(array_map('intval', $data['weekdays'])),
                windows: array_values($data['windows']),
                activatedBy: $this->actor($request),
            ));

            // La generación de los cupos es asíncrona (job encolado): la respuesta es un acuse.
            return new JsonResponse(['data' => ['status' => 'accepted', 'from' => $data['from'], 'to' => $data['to']]], 202);
        });
    }

    /**
     * Catálogos para armar una activación de cupos desde la app, en un solo request: municipios (coverage),
     * franjas ofrecidas, unidades válidas, días de la semana y las ventanas por defecto de las reglas de la
     * red. Los valores son exactamente los que acepta `open` (y los que usa `open-default`).
     */
    public function options(): JsonResponse
    {
        return $this->guard(function (): JsonResponse {
            $options = $this->activationOptions();

            $units = [];
            foreach ($options->units as $unit) {
                $label = __($unit['label']);
                $units[] = ['value' => $unit['value'], 'label' => is_string($label) ? $label : $unit['label']];
            }

            $municipalities = [];
            foreach ($this->municipalities() as $municipality) {
                $municipalities[] = [
                    'id' => $municipality->id,
                    'name' => $municipality->name,
                    'department_id' => $municipality->departmentId,
                    'department_name' => $municipality->departmentName,
                    'label' => $municipality->label(),
                ];
            }

            return new JsonResponse([
                'data' => [
                    'municipalities' => $municipalities,
                    'time_slots' => $options->timeSlots,
                    'time_slot_format' => 'HH:MM-HH:MM',
                    'units' => $units,
                    'default_windows' => $options->defaultWindows,
                    'weekdays' => $options->weekdays,
                    'service_days' => $options->serviceDays,
                ],
            ]);
        });
    }

    /**
     * Variante simple de `open`: habilita una fecha (`date`) o un rango (`from` + `to`) con las ventanas por
     * defecto de las reglas de la red. Sin `municipality_ids` aplica a los municipios del catálogo completo
     * (lo mismo que «Apply to all municipalities» del admin); sin `weekdays` aplica a los siete días.
     * Despacha el mismo ActivateCapacityWindowCommand que `open`.
     */
    public function openDefault(Request $request): JsonResponse
    {
        /** @var array{date?: string|null, from?: string|null, to?: string|null, municipality_ids?: list<string>|null, weekdays?: list<int>|null} $data */
        $data = $request->validate([
            // O una sola fecha, o un rango from + to (no ambos).
            'date' => ['nullable', 'required_without:from', 'prohibits:from,to', 'date_format:Y-m-d'],
            'from' => ['nullable', 'required_without:date', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'municipality_ids' => ['sometimes', 'nullable', 'array', 'max:400'],
            'municipality_ids.*' => ['required', 'uuid'],
            // 1 = lunes … 7 = domingo. Ausente = los siete días.
            'weekdays' => ['sometimes', 'nullable', 'array', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7'],
        ]);

        return $this->guard(function () use ($data, $request): JsonResponse {
            $from = (string) ($data['date'] ?? $data['from'] ?? '');
            $to = (string) ($data['date'] ?? $data['to'] ?? '');

            $options = $this->activationOptions();
            if (!$options->hasDefaultWindows()) {
                throw CapacityException::because('las reglas de cupo no definen ventanas por defecto (franjas con cupo): configurá las reglas del calendario o usá la activación con ventanas explícitas');
            }

            $requested = array_values(array_unique($data['municipality_ids'] ?? []));
            $allMunicipalities = $requested === [];
            $municipalityIds = $allMunicipalities
                ? array_map(static fn (MunicipalityView $view): string => $view->id, $this->municipalities())
                : $requested;
            if ($municipalityIds === []) {
                throw CapacityException::because('no hay municipios en el catálogo de cobertura para habilitar la fecha');
            }

            $weekdays = array_values(array_unique(array_map('intval', $data['weekdays'] ?? [])));
            sort($weekdays);
            if ($weekdays === []) {
                $weekdays = $options->weekdays;
            }

            $this->commandBus->dispatch(new ActivateCapacityWindowCommand(
                municipalityIds: $municipalityIds,
                fromDate: $from,
                toDate: $to,
                weekdays: $weekdays,
                windows: $options->defaultWindows,
                activatedBy: $this->actor($request),
            ));

            // La generación de los cupos es asíncrona (job encolado): la respuesta es un acuse con lo pedido.
            return new JsonResponse([
                'data' => [
                    'status' => 'accepted',
                    'from' => $from,
                    'to' => $to,
                    'weekdays' => $weekdays,
                    'all_municipalities' => $allMunicipalities,
                    'municipalities_count' => count($municipalityIds),
                    'municipality_ids' => $municipalityIds,
                    'windows' => $options->defaultWindows,
                ],
            ], 202);
        });
    }

    /**
     * Catálogo de activación según las reglas de cupo de la red (lado de lectura de capacity).
     */
    private function activationOptions(): ActivationOptionsView
    {
        /** @var ActivationOptionsView $options */
        $options = $this->queryBus->ask(new GetActivationOptionsQuery);

        return $options;
    }

    /**
     * Municipios del catálogo de coverage, por su lado de consulta (misma fuente que el admin web).
     *
     * @return list<MunicipalityView>
     */
    private function municipalities(): array
    {
        /** @var array<int, MunicipalityView> $municipalities */
        $municipalities = $this->queryBus->ask(new ListMunicipalities);

        return array_values($municipalities);
    }

    /**
     * @param callable(): JsonResponse $action
     */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CapacityException $e) {
            $code = 'business_rule_violation';

            return new JsonResponse([
                'type' => rtrim((string) config('api.problem_type_base_uri', '/problems'), '/').'/'.$code,
                'title' => 'Business rule violation',
                'status' => 422,
                'code' => $code,
                'detail' => $e->getMessage(),
                'error' => ['code' => $code, 'message' => $e->getMessage()],
            ], 422, ['Content-Type' => 'application/problem+json']);
        }
    }

    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
