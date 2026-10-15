<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\RecordExpense\RecordExpense;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\RecordExpenseRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra un gasto de flota contra un vehículo (comando RecordExpense). El importe llega como
 * centavos + moneda.
 */
final class RecordExpenseController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RecordExpenseRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $this->commands->dispatch(new RecordExpense(
                vehicleId: $request->string('vehicle_id')->toString(),
                category: $request->string('category')->toString(),
                amountCents: $request->integer('amount_cents'),
                currency: $request->string('currency')->toString(),
                incurredAt: $request->string('incurred_at')->toString(),
                description: $request->filled('description') ? $request->string('description')->toString() : null,
                note: $request->filled('note') ? $request->string('note')->toString() : null,
            ));

            return $this->accepted(['vehicle_id' => $request->string('vehicle_id')->toString()]);
        });
    }
}
