<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Shipments\Application\Query\CountShipmentsByStatus\CountShipmentsByStatus;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\Query\ListShipmentsByCursor\ListShipmentsByCursor;
use Logistics\Shipments\Application\ReadModel\ShipmentPage;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;

/**
 * Puerta app: lista los pedidos recibidos, con filtros opcionales por estado (cualquier capitalización),
 * fuente, nodo destino y rango de fecha prometida (`delivery_date_from` / `delivery_date_to`). Cada ítem
 * trae además su objeto `progress` (camión, preparado, cargado, entregado, etiquetas; aditivo).
 *
 * Aditivos (auditoría app #16/#22/#26):
 *  - `status` acepta VARIOS estados: `status[]=PLANNED&status[]=RECEIVED` o `status=planned,received`;
 *  - `delivery_mode` (home_delivery / store_pickup / transfer) filtra por la modalidad derivada (fuente +
 *    nodo destino): un traspaso interno a una tienda es `transfer`, nunca `store_pickup`;
 *  - `include=count_by_status` agrega `meta.count_by_status` (conteo por estado con los mismos filtros
 *    salvo el de estado, para las pestañas), con agregado SQL y caché corta;
 *  - cada ítem trae `delivery_mode`, `address_parts` y `short_address`;
 *  - `pending_preparation=true` (#1b) deja solo los envíos asignados (PLANNED / IN_EXECUTION) sin escaneo
 *    de preparación (incluye los que ya tienen etiquetas emitidas). `false` o ausente = sin filtro. No
 *    entra en `count_by_status` (ese conteo usa los filtros SIN el de estado, y este filtro fija estados).
 *
 * Lee vía QueryBus (ListShipments → list<ShipmentView>). La validación es inline por ser filtros
 * simples de query string.
 *
 * ALCANCE POR ROL (server-side; sale del usuario autenticado, nunca de un parámetro de la app). Aplica a la
 * lista en sus tres modos de paginación y a `meta.count_by_status`:
 *  - preparador → envíos cuyo departamento de ORIGEN o de DESTINO es su ciudad (`users.city`); los que
 *    todavía no tienen ninguno de los dos resueltos se muestran. Sin ciudad asignada no queda restringido;
 *  - tienda → envíos que SALEN de su nodo o LLEGAN a él (`users.node_id`). Se ignora el
 *    `destination_node_id` que mande el cliente. Una tienda sin nodo asignado recibe lista vacía;
 *  - chofer → envíos de sus rutas; transportista → envíos de sus viajes;
 *  - supervisor / admin → sin restricción (pueden pasar el filtro opcional `destination_node_id`).
 * Con varios roles alcanza con que uno habilite el envío. La regla vive en un solo lugar (ShipmentScopeReader
 * / ShipmentSqlFilters::scope). Para CONSULTAR cualquier pedido está GET /orders/lookup, sin alcance.
 * Esta es la única puerta del controller (shipments.php lo registra solo en `app`).
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
        $modes = array_map(static fn (DeliveryMode $mode): string => $mode->value, DeliveryMode::cases());

        // El estado se acepta en cualquier capitalización (planned / PLANNED / Planned): se normaliza a
        // mayúsculas ANTES de validar, así solo un valor realmente desconocido devuelve 422. Aditivo: varios
        // estados por `status[]=` o separados por coma; un único estado conserva el camino histórico.
        $statusList = $this->statusList($request->query('status'));
        $isMultiStatus = $statusList !== null && count($statusList) > 1;
        $this->normalizeQuery($request, $statusList, $isMultiStatus);

        /** @var array{source?: string|null, destination_node_id?: string|null, delivery_date_from?: string|null, delivery_date_to?: string|null, delivery_mode?: string|null, include?: string|null, pending_preparation?: bool|string|int|null, q?: string|null, ready_for_pickup?: bool|string|int|null, city?: string|null, vehicle_id?: string|null, delivery_date?: string|null} $filters */
        $filters = $request->validate([
            'status' => $isMultiStatus ? ['array', 'max:'.count($statuses)] : ['nullable', 'string', Rule::in($statuses)],
            'status.*' => ['string', Rule::in($statuses)],
            'source' => ['nullable', 'string', Rule::in($sources)],
            'destination_node_id' => ['nullable', 'uuid'],
            // Rango de fecha PROMETIDA de entrega (Y-m-d, ambos inclusive).
            'delivery_date_from' => ['nullable', 'date_format:Y-m-d'],
            'delivery_date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:delivery_date_from'],
            'delivery_mode' => ['nullable', 'string', Rule::in($modes)],
            'include' => ['nullable', 'string', 'max:200'],
            'pending_preparation' => ['nullable', 'boolean'],
            // Búsqueda en el servidor: código de pedido, nombre o documento del cliente.
            'q' => ['nullable', 'string', 'max:120'],
            // Solo pedidos que ya están en la tienda listos para retirar.
            'ready_for_pickup' => ['nullable', 'boolean'],
            // Ciudad (texto) y vehículo asignado.
            'city' => ['nullable', 'string', 'max:80'],
            'vehicle_id' => ['nullable', 'uuid'],
            // Atajo de un solo día (equivale a delivery_date_from = delivery_date_to).
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // Alcance por rol, armado desde el usuario autenticado (no se confía en la app).
        $scope = ScopeActorResolver::fromUser($request->user());

        // Restringido y sin ninguna habilitación (p. ej. tienda sin nodo asignado): lista vacía.
        if ($scope->seesNothing()) {
            return $this->data([]);
        }

        // El filtro opcional por nodo destino es de quien opera sin restricción; para una tienda manda su
        // alcance (lo que sale de su nodo o llega a él) y se ignora el que envíe el cliente.
        $destinationNodeId = $scope->storeNodeId !== null ? null : ($filters['destination_node_id'] ?? null);

        // Filtros del listado sin recorte; cada modo de paginación los copia a su propia consulta.
        $criteria = $this->criteria($statusList, $isMultiStatus, $filters, $destinationNodeId, $scope);

        $countByStatus = $this->wantsCountByStatus($filters['include'] ?? null)
            ? $this->countByStatus($criteria)
            : null;

        // Paginación por CURSOR (keyset) — modo PREFERIDO para los envíos (colección que crece): avanza por
        // `id > afterId` (UUID v7 ordenable) sin OFFSET, así una página profunda cuesta lo mismo que la
        // primera. El cursor es un token opaco (no se filtra el modelo de almacenamiento); el id viaja
        // codificado, nunca como texto visible.
        if ($request->has('cursor')) {
            return $this->respondByCursor($request, $criteria, $countByStatus);
        }

        // Paginación por OFFSET numerada (?page/?per_page): se CONSERVA porque la puerta app expone páginas
        // numeradas con `total`/`total_pages` (contrato E-02 que la app ya consume). El COUNT(*) va aparte;
        // ya no se materializa la tabla completa para trocearla en PHP. Para colecciones grandes, preferir cursor.
        if (ApiPagination::requested($request)) {
            return $this->respondByOffset($request, $criteria, $countByStatus);
        }

        return $this->respondUnpaginated($criteria, $countByStatus);
    }

    /**
     * Normaliza la query string ANTES de validar: estado(s) en mayúsculas, `delivery_mode` en minúsculas y
     * `pending_preparation` true/false a 1/0.
     *
     * @param list<mixed>|null $statusList
     */
    private function normalizeQuery(Request $request, ?array $statusList, bool $isMultiStatus): void
    {
        if ($statusList !== null) {
            $request->merge(['status' => $isMultiStatus ? $statusList : ($statusList[0] ?? null)]);
        }

        $rawMode = $request->query('delivery_mode');
        if (is_string($rawMode) && trim($rawMode) !== '') {
            $request->merge(['delivery_mode' => strtolower(trim($rawMode))]);
        }

        // `pending_preparation=true|false` llega como texto en la query string: la regla `boolean` de Laravel
        // solo acepta 1/0, así que true/false (cualquier capitalización) se traducen antes de validar.
        // Lo mismo vale para `ready_for_pickup`.
        foreach (['pending_preparation', 'ready_for_pickup'] as $flag) {
            $raw = $request->query($flag);
            if (is_string($raw)) {
                $normalized = strtolower(trim($raw));
                $request->merge([$flag => match ($normalized) {
                    'true' => '1',
                    'false' => '0',
                    default => $normalized,
                }]);
            }
        }
    }

    /**
     * Arma los filtros del listado (sin recorte). Un estado → filtro `status` (camino histórico); varios →
     * filtro `statuses` (IN).
     *
     * @param list<mixed>|null                    $statusList
     * @param array<string, bool|int|string|null> $filters
     * @param ShipmentScopeActor                  $scope      alcance por rol de quien lista
     */
    private function criteria(?array $statusList, bool $isMultiStatus, array $filters, ?string $destinationNodeId, ShipmentScopeActor $scope): ListShipments
    {
        $validStatuses = array_values(array_filter($statusList ?? [], static fn (mixed $value): bool => is_string($value)));

        return new ListShipments(
            status: $isMultiStatus ? null : ($validStatuses[0] ?? null),
            source: self::stringOrNull($filters['source'] ?? null),
            destinationNodeId: $destinationNodeId,
            deliveryDateFrom: self::stringOrNull($filters['delivery_date_from'] ?? $filters['delivery_date'] ?? null),
            deliveryDateTo: self::stringOrNull($filters['delivery_date_to'] ?? $filters['delivery_date'] ?? null),
            statuses: $isMultiStatus ? $validStatuses : null,
            deliveryMode: self::stringOrNull($filters['delivery_mode'] ?? null),
            pendingPreparation: filter_var($filters['pending_preparation'] ?? false, FILTER_VALIDATE_BOOLEAN),
            search: self::stringOrNull($filters['q'] ?? null),
            readyForPickup: filter_var($filters['ready_for_pickup'] ?? false, FILTER_VALIDATE_BOOLEAN),
            city: self::stringOrNull($filters['city'] ?? null),
            vehicleId: self::stringOrNull($filters['vehicle_id'] ?? null),
            scope: $scope,
        );
    }

    /**
     * Valor de filtro de texto ya validado, o null.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Si `include` pide `count_by_status` (lista separada por comas).
     */
    private function wantsCountByStatus(?string $include): bool
    {
        return in_array('count_by_status', array_map('trim', explode(',', (string) ($include ?? ''))), true);
    }

    /**
     * Respuesta paginada por cursor (keyset): `meta` con `count`, `per_page`, `has_more` y `next_cursor`.
     *
     * @param array<string, int>|null $countByStatus
     */
    private function respondByCursor(Request $request, ListShipments $criteria, ?array $countByStatus): JsonResponse
    {
        $perPage = ApiPagination::limit($request);

        /** @var ShipmentPage $page */
        $page = $this->queryBus->ask(new ListShipmentsByCursor(
            limit: $perPage,
            afterId: $this->decodeCursor($request->input('cursor')),
            status: $criteria->status,
            source: $criteria->source,
            destinationNodeId: $criteria->destinationNodeId,
            deliveryDateFrom: $criteria->deliveryDateFrom,
            deliveryDateTo: $criteria->deliveryDateTo,
            statuses: $criteria->statuses,
            deliveryMode: $criteria->deliveryMode,
            pendingPreparation: $criteria->pendingPreparation,
            search: $criteria->search,
            readyForPickup: $criteria->readyForPickup,
            city: $criteria->city,
            vehicleId: $criteria->vehicleId,
            scope: $criteria->scope,
        ));

        $items = array_map(static fn (ShipmentView $view): array => $view->toArray(), $page->items);
        $hasMore = $page->nextCursor !== null;

        $meta = [
            'count' => count($items),
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $page->nextCursor !== null ? $this->encodeCursor($page->nextCursor) : null,
        ];
        if ($countByStatus !== null) {
            $meta['count_by_status'] = $countByStatus;
        }

        return new JsonResponse([
            'data' => array_values($items),
            'meta' => $meta,
        ]);
    }

    /**
     * Respuesta paginada por offset numerado (envelope con `total`/`total_pages`).
     *
     * @param array<string, int>|null $countByStatus
     */
    private function respondByOffset(Request $request, ListShipments $criteria, ?array $countByStatus): JsonResponse
    {
        /** @var Page<ShipmentView> $page */
        $page = $this->queryBus->ask(new ListShipments(
            status: $criteria->status,
            source: $criteria->source,
            destinationNodeId: $criteria->destinationNodeId,
            limit: ApiPagination::limit($request),
            offset: ApiPagination::offset($request),
            deliveryDateFrom: $criteria->deliveryDateFrom,
            deliveryDateTo: $criteria->deliveryDateTo,
            statuses: $criteria->statuses,
            deliveryMode: $criteria->deliveryMode,
            pendingPreparation: $criteria->pendingPreparation,
            search: $criteria->search,
            readyForPickup: $criteria->readyForPickup,
            city: $criteria->city,
            vehicleId: $criteria->vehicleId,
            scope: $criteria->scope,
        ));

        $items = array_map(static fn (ShipmentView $view): array => $view->toArray(), $page->items);

        $envelope = ApiPagination::envelope($items, $page->total, $request);
        if ($countByStatus !== null) {
            $envelope['meta']['count_by_status'] = $countByStatus;
        }

        return new JsonResponse($envelope);
    }

    /**
     * Respuesta sin paginar (camino histórico): `data` y, si se pidió, `meta.count_by_status`.
     *
     * @param array<string, int>|null $countByStatus
     */
    private function respondUnpaginated(ListShipments $criteria, ?array $countByStatus): JsonResponse
    {
        /** @var list<ShipmentView> $views */
        $views = $this->queryBus->ask($criteria);

        $data = array_map(static fn (ShipmentView $view): array => $view->toArray(), $views);

        if ($countByStatus !== null) {
            return new JsonResponse(['data' => $data, 'meta' => ['count_by_status' => $countByStatus]]);
        }

        return $this->data($data);
    }

    /**
     * Estados pedidos, normalizados a mayúsculas y sin vacíos: `status[]=a&status[]=b`, `status=a,b` o
     * `status=a`. Null si no se pidió ninguno. Valores no-string se conservan para que la validación los
     * rechace con 422.
     *
     * @return list<mixed>|null
     */
    private function statusList(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $values = array_values($raw);
        } else {
            $values = is_string($raw) ? explode(',', $raw) : [];
        }

        $list = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                $list[] = $value;

                continue;
            }
            if (trim($value) !== '') {
                $list[] = strtoupper(trim($value));
            }
        }

        return $list === [] ? null : array_values(array_unique($list, SORT_REGULAR));
    }

    /**
     * Conteo por estado con los filtros del listado salvo el de estado (`meta.count_by_status`).
     *
     * @return array<string, int>
     */
    private function countByStatus(ListShipments $criteria): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->queryBus->ask(new CountShipmentsByStatus(
            source: $criteria->source,
            destinationNodeId: $criteria->destinationNodeId,
            deliveryDateFrom: $criteria->deliveryDateFrom,
            deliveryDateTo: $criteria->deliveryDateTo,
            deliveryMode: $criteria->deliveryMode,
            scope: $criteria->scope,
        ));

        return $counts;
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
