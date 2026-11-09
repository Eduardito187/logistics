<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use App\Enums\Role;
use App\Http\Support\ApiPagination;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\Query\ListShipmentsByCursor\ListShipmentsByCursor;
use Logistics\Shipments\Application\ReadModel\ShipmentPage;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;

/**
 * Puerta app: lista los pedidos recibidos, con filtros opcionales por estado y fuente.
 *
 * Lee vía QueryBus (ListShipments → list<ShipmentView>). La validación es inline por ser filtros
 * simples de query string.
 *
 * Alcance por tienda (server-side): si el usuario autenticado tiene rol Tienda, el listado se ACOTA a su
 * nodo asignado (`users.node_id`), ignorando cualquier `destination_node_id` que mande el cliente (no se
 * confía en la app). Una tienda sin nodo asignado recibe lista vacía (seguro: nunca ve envíos ajenos).
 * Los demás roles operan cross-nodo y pueden pasar el filtro opcional `destination_node_id` o ninguno.
 *
 * Nota: el nodo destino lo pobla el ingest/SAP; mientras sea null, una tienda no verá esos envíos (su
 * nodo nunca coincide con null), que es exactamente la garantía buscada: no filtrar envíos de otras
 * tiendas. Esta es la única puerta del controller (shipments.php lo registra solo en `app`).
 */
final class ListShipmentsController
{
    use JsonEnvelope;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $statuses = array_map(static fn (ShipmentStatus $status): string => $status->value, ShipmentStatus::cases());
        $sources = array_map(static fn (OrderSource $source): string => $source->value, OrderSource::cases());

        /** @var array{status?: string|null, source?: string|null, destination_node_id?: string|null} $filters */
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in($statuses)],
            'source' => ['nullable', 'string', Rule::in($sources)],
            'destination_node_id' => ['nullable', 'uuid'],
        ]);

        // Resuelve el filtro de nodo destino: para el rol Tienda se FUERZA su nodo asignado (server-side,
        // ignorando lo que mande el cliente); el resto de roles puede pasar el filtro opcional.
        $destinationNodeId = $filters['destination_node_id'] ?? null;

        $user = $request->user();
        if ($user instanceof User && $user->hasRole(Role::Tienda)) {
            $assignedNode = $user->node_id;

            // Tienda sin nodo asignado: lista vacía (no puede ver envíos de ninguna tienda).
            if (!is_string($assignedNode) || $assignedNode === '') {
                return $this->data([]);
            }

            $destinationNodeId = $assignedNode;
        }

        // Paginación por CURSOR (keyset) — modo PREFERIDO para los envíos (colección que crece): avanza por
        // `id > afterId` (UUID v7 ordenable) sin OFFSET, así una página profunda cuesta lo mismo que la
        // primera. El cursor es un token opaco (no se filtra el modelo de almacenamiento); el id viaja
        // codificado, nunca como texto visible.
        if ($request->has('cursor')) {
            $perPage = ApiPagination::limit($request);

            /** @var ShipmentPage $page */
            $page = $this->queryBus->ask(new ListShipmentsByCursor(
                limit: $perPage,
                afterId: $this->decodeCursor($request->input('cursor')),
                status: $filters['status'] ?? null,
                source: $filters['source'] ?? null,
                destinationNodeId: $destinationNodeId,
            ));

            $items = array_map(static fn (ShipmentView $view): array => $view->toArray(), $page->items);
            $hasMore = $page->nextCursor !== null;

            return new JsonResponse([
                'data' => array_values($items),
                'meta' => [
                    'count' => count($items),
                    'per_page' => $perPage,
                    'has_more' => $hasMore,
                    'next_cursor' => $hasMore && $page->nextCursor !== null ? $this->encodeCursor($page->nextCursor) : null,
                ],
            ]);
        }

        // Paginación por OFFSET numerada (?page/?per_page): se CONSERVA porque la puerta app expone páginas
        // numeradas con `total`/`total_pages` (contrato E-02 que la app ya consume). El COUNT(*) va aparte;
        // ya no se materializa toda la tabla para trocearla en PHP. Para colecciones grandes, preferir cursor.
        if (ApiPagination::requested($request)) {
            /** @var Page<ShipmentView> $page */
            $page = $this->queryBus->ask(new ListShipments(
                status: $filters['status'] ?? null,
                source: $filters['source'] ?? null,
                destinationNodeId: $destinationNodeId,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));

            $items = array_map(static fn (ShipmentView $view): array => $view->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        }

        /** @var list<ShipmentView> $views */
        $views = $this->queryBus->ask(new ListShipments(
            status: $filters['status'] ?? null,
            source: $filters['source'] ?? null,
            destinationNodeId: $destinationNodeId,
        ));

        return $this->data(array_map(static fn (ShipmentView $view): array => $view->toArray(), $views));
    }

    /**
     * Cursor keyset OPACO: base64 de `id:<uuid>`. No se muestra al usuario ni filtra el almacenamiento;
     * el cliente solo lo reenvía tal cual para pedir la página siguiente.
     */
    private function encodeCursor(string $id): string
    {
        return base64_encode('id:'.$id);
    }

    /**
     * Decodifica el cursor opaco a su id de envío. Devuelve null para la primera página (cursor ausente,
     * vacío o malformado): nunca falla, solo arranca desde el principio.
     */
    private function decodeCursor(mixed $cursor): ?string
    {
        if (!is_string($cursor) || $cursor === '') {
            return null;
        }

        $raw = base64_decode($cursor, true);
        if ($raw === false || !str_starts_with($raw, 'id:')) {
            return null;
        }

        $id = substr($raw, 3);

        return $id !== '' ? $id : null;
    }
}
