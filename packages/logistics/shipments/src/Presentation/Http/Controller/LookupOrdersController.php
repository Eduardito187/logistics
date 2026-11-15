<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\LookupOrders\LookupOrders;
use Logistics\Shipments\Application\ReadModel\OrderLookupResult;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;

/**
 * Puerta app: GET /orders/lookup?q=<código de pedido | identificador de etiqueta> — consulta ABIERTA de
 * pedidos, solo lectura, para CUALQUIER rol autenticado de la app.
 *
 * Regla del dueño: cualquier usuario puede escanear o buscar cualquier pedido para ver su información,
 * tenga o no rol para procesarlo. Por eso, a diferencia de GET /shipments, acá NO se aplica alcance por rol
 * (una tienda encuentra pedidos de otra tienda; un preparador, los de otra ciudad). El usuario solo se usa
 * para calcular `can_process` de cada envío con la regla de alcance (false = «solo consulta»).
 *
 * Buscar por NOMBRE o DOCUMENTO del cliente queda para oficina y tienda (admin, supervisor, tienda: el
 * cliente pregunta en la tienda). Los demás roles consultan cualquier pedido igual, pero solo por código de
 * pedido, referencia o etiqueta escaneada: para ellos el texto no coincide con datos del cliente (no es 403).
 *
 * Respuesta: lista corta (hasta 10) con la forma del envío del listado (incluye `progress`), más
 * `order_reference`, `destination_node`, `origin_node`, `origin_department`, `destination_department`,
 * `tracking` y `can_process`. Sin coincidencias → 404.
 */
final class LookupOrdersController
{
    use JsonEnvelope;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{q: string} $input */
        $input = $request->validate([
            'q' => ['required', 'string', 'max:120'],
        ]);

        $term = trim($input['q']);

        /** @var OrderLookupResult $result */
        $result = $this->queryBus->ask(new LookupOrders(
            $term,
            ScopeActorResolver::fromUser($request->user()),
            ScopeActorResolver::searchesCustomerData($request->user()),
        ));

        if ($result->isEmpty()) {
            return $this->error("No se encontró ningún pedido para «{$term}».", 404);
        }

        return new JsonResponse($result->toArray());
    }
}
