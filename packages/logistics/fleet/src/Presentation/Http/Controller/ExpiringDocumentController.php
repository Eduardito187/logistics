<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Endpoint HTTP del tablero de documentos por vencer (vehículos y choferes).
 *
 * Es solo lectura: alimenta el panel de vencimientos de la app interna y el admin.
 */
final readonly class ExpiringDocumentController
{
    use RespondsWithEnvelope;

    public function __construct(
        private QueryBus $queryBus,
    ) {}

    /**
     * GET /fleet/expiring-documents — documentos que vencen dentro de N días (incluye vencidos).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:0', 'max:365'],
            'reference_date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        /** @var list<ExpiringDocumentView> $documents */
        $documents = $this->queryBus->ask(new ListExpiringDocuments(
            days: (int) $validated['days'],
            referenceDate: $validated['reference_date'] ?? null,
        ));

        return $this->respondCollection(array_map(
            static fn (ExpiringDocumentView $view): array => $view->toArray(),
            $documents,
        ));
    }
}
