<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Query\ListNotificationsByShipment\ListNotificationsByShipment;
use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista las notificaciones de un envío, de la más reciente a la más antigua (log de envíos).
 *
 * Sirve a dos puertas con la misma consulta:
 *  - app: el id del envío llega como query param `shipment_id` (visibilidad de operación/soporte).
 *  - channels: el id del envío llega como parámetro de ruta `{shipment}` (web/call center del cliente).
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
        $views = $this->queryBus->ask(new ListNotificationsByShipment($shipmentId));

        return new JsonResponse([
            'data' => array_map(
                static fn (NotificationView $view): array => $view->toArray(),
                $views,
            ),
        ]);
    }
}
