<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Planning;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Logistics\Planning\Application\Command\GenerateDailyPlan\GenerateDailyPlan;
use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\Command\ReorderStops\ReorderStops;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Throwable;

/**
 * App web (Inertia) — escrituras del tablero de Planificación (fase 2). A diferencia del
 * PlanningController (solo lectura), aquí se despachan COMANDOS por el CommandBus respetando las capas
 * (Presentation → Application): nunca se toca Infrastructure ni el Domain directo.
 *
 * Cada acción valida la entrada, traduce la petición a un comando, lo despacha y redirige de vuelta con
 * un mensaje flash (success/error que ya comparte HandleInertiaRequests). Los errores de dominio
 * (invariantes del plan) se capturan y se muestran como flash de error, sin filtrar trazas al usuario.
 */
final class PlanningWriteController extends Controller
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    /**
     * PUT plans/{plan}/sequence — reordena por arrastre las paradas del plan al nuevo orden.
     *
     * El reordenamiento se expresa como la lista completa de envíos del plan en el orden deseado; que la
     * lista coincida con las paradas del plan es una invariante que valida el agregado (comando
     * ReorderStops), no este controlador.
     */
    public function sequence(Request $request, string $plan): RedirectResponse
    {
        $validated = $request->validate([
            'ordered_shipment_ids' => ['required', 'array', 'min:1'],
            'ordered_shipment_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ]);

        /** @var list<string> $ids */
        $ids = array_values($validated['ordered_shipment_ids']);

        return $this->dispatch(
            new ReorderStops($plan, $ids),
            'Secuencia del plan actualizada.',
        );
    }

    /**
     * POST plans/{plan}/publish — confirma el plan y lo deja disponible para la ejecución.
     */
    public function publish(string $plan): RedirectResponse
    {
        return $this->dispatch(
            new PublishPlan($plan),
            'Plan publicado.',
        );
    }

    /**
     * POST plans/auto — dispara la planificación AUTOMÁTICA del día (GenerateDailyPlan). Es asíncrona:
     * encola la corrida que procesa un worker; aquí solo se despacha el comando.
     */
    public function auto(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan_date' => ['required', 'string', 'date_format:Y-m-d'],
            'strategy' => ['required', 'string', 'in:optimization,slots'],
        ]);

        return $this->dispatch(
            new GenerateDailyPlan(
                planDate: $validated['plan_date'],
                strategy: $validated['strategy'],
            ),
            'Auto-planificación del día encolada.',
        );
    }

    /**
     * Despacha un comando y redirige de vuelta con flash de éxito, o de error si el dominio rechaza la
     * operación. No se filtra el detalle técnico al usuario; el mensaje de la excepción es descriptivo.
     */
    private function dispatch(Command $command, string $success): RedirectResponse
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
