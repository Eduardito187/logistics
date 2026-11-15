<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\CheckShipmentScope\CheckShipmentScope;
use Logistics\Shipments\Application\ReadModel\ShipmentScopeDecision;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda de ALCANCE de las escrituras de operación de la puerta app (alias `order.scope`).
 *
 * Regla del dueño: cada rol opera solo lo suyo —el preparador, los pedidos de su ciudad (origen o destino);
 * la tienda, los que salen de su nodo o llegan a él; el chofer y el transportista, los que les asignaron—.
 * Supervisor y admin operan cualquiera. CONSULTAR (lookup de pedidos, resolver una etiqueta) queda abierto y
 * no lleva esta guarda.
 *
 * Identifica el pedido o el envío de la petición por:
 *  - el parámetro de ruta `{orderNumber}` (pedido completo);
 *  - los parámetros de ruta `{shipment}` / `{shipmentId}` (un envío);
 *  - el campo `shipment_id` del cuerpo (escaneos, entrega, fallida).
 * Si el usuario no puede operarlo responde 403 con el contrato de error del proyecto (RFC 7807, motivo en
 * `detail` y en `error.message`) y un `code` propio: `out_of_scope_city` (preparador de otra ciudad),
 * `out_of_scope_node` (tienda ajena) u `out_of_scope_assignment` (chofer / transportista sin asignación).
 * Lo que no existe sigue de largo: el endpoint responde su 404 / 422 de siempre.
 */
final class EnsureOrderScope
{
    use JsonEnvelope;

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = ScopeActorResolver::fromUser($request->user());
        if ($actor->unrestricted) {
            return $next($request);
        }

        $orderNumber = $this->routeText($request, 'orderNumber');
        $shipmentIds = $this->shipmentIds($request);
        if ($orderNumber === null && $shipmentIds === []) {
            return $next($request);
        }

        /** @var ShipmentScopeDecision $decision */
        $decision = $this->queries->ask(new CheckShipmentScope($actor, $orderNumber, $shipmentIds));
        if ($decision->allowed) {
            return $next($request);
        }

        return $this->error((string) $decision->message, Response::HTTP_FORBIDDEN, $decision->code);
    }

    /**
     * Envíos nombrados por la petición (ruta y cuerpo), solo los que tienen forma de UUID.
     *
     * @return list<string>
     */
    private function shipmentIds(Request $request): array
    {
        $ids = [];
        foreach ([$this->routeText($request, 'shipment'), $this->routeText($request, 'shipmentId'), $request->input('shipment_id')] as $candidate) {
            if (is_string($candidate) && preg_match(self::UUID_PATTERN, trim($candidate)) === 1) {
                $ids[strtolower(trim($candidate))] = true;
            }
        }

        return array_map(strval(...), array_keys($ids));
    }

    private function routeText(Request $request, string $parameter): ?string
    {
        $value = $request->route($parameter);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
