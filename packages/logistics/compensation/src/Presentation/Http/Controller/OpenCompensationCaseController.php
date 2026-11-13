<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Presentation\Http\ApiController;
use Logistics\Compensation\Presentation\Http\Request\OpenCompensationCaseRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * POST /integrations/compensation/cases — abre un caso manual de compensación (call center / supervisor).
 *
 * 202 (escritura asíncrona por outbox); la idempotencia por clave la garantiza el handler (reabrir el
 * mismo caso no crea otro). Una regla de negocio violada (disparador/alcance inválido, base incoherente)
 * ⇒ 422. El proponente sale del cuerpo o, si no viene, del usuario autenticado (cuatro ojos en la aprobación).
 */
final class OpenCompensationCaseController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(OpenCompensationCaseRequest $request): JsonResponse
    {
        try {
            $this->commands->dispatch(new OpenCompensationCase(
                trigger: (string) $request->string('trigger'),
                scope: (string) $request->string('scope'),
                shipmentId: $this->nullableString($request, 'shipment_id'),
                orderReference: $this->nullableString($request, 'order_reference'),
                sourceEventId: $this->nullableString($request, 'source_event_id'),
                idempotencyKey: $this->nullableString($request, 'idempotency_key'),
                affectedSkus: $this->stringList($request, 'affected_skus'),
                manualBaseCents: $request->filled('manual_base_cents') ? $request->integer('manual_base_cents') : null,
                manualLines: $this->manualLines($request),
                proposedBy: $this->proposedBy($request),
                ownership: $this->nullableString($request, 'ownership'),
                driverId: $this->nullableString($request, 'driver_id'),
                vehicleId: $this->nullableString($request, 'vehicle_id'),
                originNodeId: $this->nullableString($request, 'origin_node_id'),
            ));
        } catch (CompensationException $e) {
            return $this->caseProblem($e, $request);
        }

        return $this->accepted([
            'trigger' => (string) $request->string('trigger'),
            'scope' => (string) $request->string('scope'),
        ]);
    }

    private function nullableString(OpenCompensationCaseRequest $request, string $key): ?string
    {
        return $request->filled($key) ? (string) $request->string($key) : null;
    }

    /**
     * Proponente del caso: el del cuerpo o, en su defecto, el id del usuario autenticado, o `system`.
     */
    private function proposedBy(OpenCompensationCaseRequest $request): string
    {
        if ($request->filled('proposed_by')) {
            return (string) $request->string('proposed_by');
        }

        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) || (is_string($id) && $id !== '') ? (string) $id : 'system';
    }

    /**
     * @return list<string>
     */
    private function stringList(OpenCompensationCaseRequest $request, string $key): array
    {
        $values = $request->input($key, []);
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $v): string => (string) $v, $values));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manualLines(OpenCompensationCaseRequest $request): array
    {
        $lines = $request->input('manual_lines', []);
        if (!is_array($lines)) {
            return [];
        }

        $out = [];
        foreach ($lines as $line) {
            if (is_array($line)) {
                $out[] = $line;
            }
        }

        return $out;
    }
}
