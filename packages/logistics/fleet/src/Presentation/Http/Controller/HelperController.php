<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Command\RegisterHelper\RegisterHelper;
use Logistics\Fleet\Application\Command\SetHelperAvailability\SetHelperAvailability;
use Logistics\Fleet\Application\Query\ListHelpers\ListHelpers;
use Logistics\Fleet\Application\ReadModel\HelperView;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Endpoints HTTP de ayudantes. Habla solo con los buses CQRS.
 *
 * - index: lectura (opcionalmente solo activos) para la app interna y el tablero.
 * - store: alta de un ayudante (sincronización de datos maestros de RR. HH. vía integraciones).
 * - setAvailability: activar o dar de baja un ayudante desde la app interna.
 */
final readonly class HelperController
{
    use RespondsWithEnvelope;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    /**
     * GET /fleet/helpers — lista los ayudantes de la flota.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'only_active' => ['sometimes', 'boolean'],
        ]);

        $onlyActive = $request->has('only_active') ? $request->boolean('only_active') : null;

        /** @var list<HelperView> $helpers */
        $helpers = $this->queryBus->ask(new ListHelpers($onlyActive));

        return $this->respondCollection(array_map(
            static fn (HelperView $view): array => $view->toArray(),
            $helpers,
        ));
    }

    /**
     * POST /fleet/helpers — registra un ayudante nuevo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'active' => ['sometimes', 'boolean'],
            'documents' => ['sometimes', 'array'],
            'documents.*.type' => ['required_with:documents', 'string', 'max:50'],
            'documents.*.expires_on' => ['required_with:documents', 'date_format:Y-m-d'],
        ]);

        /** @var list<array{type: string, expires_on: string}> $documents */
        $documents = array_values($validated['documents'] ?? []);

        $this->commandBus->dispatch(new RegisterHelper(
            name: $validated['name'],
            phone: $validated['phone'] ?? null,
            documents: $documents,
            active: $request->boolean('active', true),
        ));

        return $this->respondAccepted(['name' => $validated['name']]);
    }

    /**
     * PATCH /fleet/helpers/{helperId}/availability — activa o da de baja a un ayudante.
     */
    public function setAvailability(Request $request, string $helperId): JsonResponse
    {
        $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $active = $request->boolean('active');

        $this->commandBus->dispatch(new SetHelperAvailability(
            helperId: $helperId,
            active: $active,
        ));

        return $this->respondAccepted([
            'helper_id' => $helperId,
            'active' => $active,
        ], 200);
    }
}
