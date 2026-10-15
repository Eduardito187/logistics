<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Command\RegisterDocument\RegisterDocument;
use Logistics\Fleet\Application\Query\ListDocuments\ListDocuments;
use Logistics\Fleet\Application\ReadModel\DocumentView;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Endpoints HTTP de documentos de flota (con vencimiento y archivo S3). Habla solo con los buses CQRS.
 *
 * - index: lectura (filtrable por tipo de dueño) para la app interna y el tablero de vencimientos.
 * - store: alta de un documento (sincronización de datos maestros vía integraciones).
 */
final readonly class DocumentController
{
    use RespondsWithEnvelope;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    /**
     * GET /fleet/documents — lista los documentos de flota.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_type' => ['sometimes', 'string', 'in:vehicle,driver,helper'],
        ]);

        /** @var list<DocumentView> $documents */
        $documents = $this->queryBus->ask(new ListDocuments($validated['owner_type'] ?? null));

        return $this->respondCollection(array_map(
            static fn (DocumentView $view): array => $view->toArray(),
            $documents,
        ));
    }

    /**
     * POST /fleet/documents — registra un documento de flota.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_type' => ['required', 'string', 'in:vehicle,driver,helper'],
            'owner_id' => ['required', 'uuid'],
            'document_type' => ['required', 'string', 'max:50'],
            'expires_on' => ['required', 'date_format:Y-m-d'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'file_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $this->commandBus->dispatch(new RegisterDocument(
            ownerType: $validated['owner_type'],
            ownerId: $validated['owner_id'],
            documentType: $validated['document_type'],
            expiresOn: $validated['expires_on'],
            issuedOn: $validated['issued_on'] ?? null,
            filePath: $validated['file_path'] ?? null,
            note: $validated['note'] ?? null,
            reference: $validated['reference'] ?? null,
        ));

        return $this->respondAccepted([
            'owner_type' => $validated['owner_type'],
            'owner_id' => $validated['owner_id'],
            'document_type' => $validated['document_type'],
        ]);
    }
}
