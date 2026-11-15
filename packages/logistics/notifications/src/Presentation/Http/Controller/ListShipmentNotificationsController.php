<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Query\ListNotificationsByShipment\ListNotificationsByShipment;
use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;

/**
 * Lista las notificaciones de un envío, de la más reciente a la más antigua (log de envíos).
 *
 * Sirve a dos puertas con la misma consulta:
 *  - app: el id del envío llega como query param `shipment_id` (visibilidad de operación/soporte).
 *  - channels: el id del envío llega como parámetro de ruta `{shipment}` (web/call center del cliente).
 *
 * Alcance por rol (solo puerta app): si el usuario no puede ver ese envío, la lista sale vacía. La puerta
 * de canales no se acota por rol (su credencial es del canal, no de un usuario de la operación).
 *
 * Solo habla con el QueryBus y devuelve NotificationView en forma primitiva; nunca el objeto de dominio.
 */
final readonly class ListShipmentNotificationsController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request, ?string $shipment = null): JsonResponse
    {
        // El id del envío viene de la ruta (channels) o del query string (app); se valida igual.
        $shipmentId = $shipment ?? (string) $request->query('shipment_id', '');

        Validator::make(
            ['shipment_id' => $shipmentId],
            ['shipment_id' => ['required', 'uuid']],
        )->validate();

        /** @var list<NotificationView> $views */
        $views = $this->queryBus->ask(new ListNotificationsByShipment($shipmentId, $this->scope($request, $shipment)));

        return new JsonResponse([
            'data' => array_map(
                static fn (NotificationView $view): array => $view->toArray(),
                $views,
            ),
        ]);
    }

    /**
     * Alcance de quien consulta. El parámetro de ruta `{shipment}` identifica la puerta de canales: ahí no
     * hay usuario de la operación a quien acotar.
     */
    private function scope(Request $request, ?string $routeShipment): ShipmentScopeActor
    {
        if ($routeShipment !== null) {
            return ShipmentScopeActor::unrestricted();
        }

        return ScopeActorResolver::fromUser($request->user());
    }
}
