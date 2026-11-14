<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Execution\Application\Command\RecordStoreDrop\RecordStoreDrop;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;

/**
 * POST /app/deliveries/{shipmentId}/store-drop — ENTREGA EN TIENDA del chofer (puerta app).
 *
 * El camión deja en la tienda un pedido que el cliente retira ahí (o un traspaso): el chofer escanea los
 * productos y saca una foto, sin firma ni documento. Este POST cierra SU parada. El pedido NO queda
 * entregado: queda «listo para retirar» (`progress.ready_for_pickup_at`) y, si es un retiro de cliente, se
 * le avisa que ya puede pasar a recogerlo. La entrega al cliente la registra después la tienda, con la
 * prueba de entrega de mostrador.
 *
 * Solo aplica a envíos cuyo destino es un nodo (`store_pickup` o `transfer`) y en ESE nodo: una entrega a
 * domicilio u otra tienda responde 422. Idempotente: repetirlo no duplica el aviso ni el hecho.
 */
final class RecordStoreDropController extends ExecutionController
{
    private const MODES = ['store_pickup', 'transfer'];

    public function __invoke(Request $request, string $shipmentId): JsonResponse
    {
        /** @var array{node_id?: string|null, photo_path?: string|null, occurred_at?: string|null} $data */
        $data = $request->validate([
            'node_id' => ['sometimes', 'nullable', 'uuid'],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'occurred_at' => ['sometimes', 'nullable', 'date'],
            // La posición es informativa (la app la manda con el resto de los escaneos).
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ]);

        $shipment = $this->queryBus->ask(new FindShipment($shipmentId));
        if (!$shipment instanceof ShipmentView) {
            return $this->problem('shipment_not_found', 'Shipment not found.', 404);
        }

        $destination = (string) ($shipment->destinationNodeId ?? '');
        if ($destination === '' || !in_array($shipment->deliveryMode, self::MODES, true)) {
            return $this->problem(
                'not_a_store_drop',
                'This shipment is delivered to the customer address, not dropped at a store.',
                422,
            );
        }

        $nodeId = isset($data['node_id']) && $data['node_id'] !== '' ? (string) $data['node_id'] : $destination;
        if ($nodeId !== $destination) {
            return $this->problem(
                'wrong_store',
                'This shipment belongs to another store.',
                422,
            );
        }

        $occurredAt = isset($data['occurred_at']) && $data['occurred_at'] !== ''
            ? (string) $data['occurred_at']
            : now()->toAtomString();
        $photoPath = isset($data['photo_path']) && trim((string) $data['photo_path']) !== ''
            ? trim((string) $data['photo_path'])
            : null;

        $this->commandBus->dispatch(new RecordStoreDrop($shipmentId, $nodeId, $occurredAt, $photoPath));

        return $this->deliveryStateResponse($shipmentId);
    }
}
