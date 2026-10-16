<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use App\Enums\Role;
use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Presentation\Http\Request\ProofOfDeliveryRequest;

/**
 * POST /app — registra la prueba de entrega y cierra la entrega como entregada (puerta app).
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (cargada o en tránsito).
 *
 * Alcance por tienda (server-side): el POD es por shipmentId (no manda nodeId en el body), así que el
 * middleware enforce.store.node no aplica directo. Aquí se replica su garantía para el rol Tienda: se lee
 * el nodo destino del envío (lectura directa a `shipments`, enriquecimiento cross-context como el resto
 * del admin) y, si no coincide con el nodo asignado del usuario, se responde 403 RFC 7807. Si el envío no
 * tiene nodo destino (null, aún no poblado por el ingest/SAP), la tienda NO puede probar que es suyo → 403
 * (política segura). Los demás roles operan cross-nodo y pasan directo.
 */
final class RecordProofOfDeliveryController extends ExecutionController
{
    public function __invoke(ProofOfDeliveryRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $shipmentId = (string) $data['shipment_id'];

        $denied = $this->enforceStoreNodeScope($request, $shipmentId);
        if ($denied !== null) {
            return $denied;
        }

        $photoUrl = $data['photo_url'] ?? null;
        $photoPath = $data['photo_path'] ?? null;

        $this->commandBus->dispatch(new RecordProofOfDelivery(
            shipmentId: (string) $data['shipment_id'],
            unitId: (string) $data['unit_id'],
            confirmation: (string) $data['confirmation'],
            photoUrl: is_string($photoUrl) ? $photoUrl : null,
            photoPath: is_string($photoPath) ? $photoPath : null,
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            occurredAt: (string) $data['occurred_at'],
            idempotencyKey: $this->idempotencyKey($request),
        ));

        return $this->deliveryStateResponse($shipmentId);
    }

    /**
     * Acota el POD al nodo de la tienda del usuario. Devuelve una respuesta 403 si el usuario Tienda
     * intenta registrar la entrega de un envío cuyo nodo destino no es el suyo (o aún no está poblado);
     * devuelve null cuando la acción está permitida (otros roles, o Tienda sobre su propio nodo).
     */
    private function enforceStoreNodeScope(ProofOfDeliveryRequest $request, string $shipmentId): ?JsonResponse
    {
        $user = $request->user();

        // Solo el rol Tienda queda acotado a su nodo; el resto opera cross-nodo sin restricción.
        if (!$user instanceof User || !$user->hasRole(Role::Tienda)) {
            return null;
        }

        $assignedNode = $user->node_id;

        // Lectura directa del nodo destino del envío (enriquecimiento cross-context, sin acoplar el
        // dominio de Ejecución a la tabla de Shipments; al extraerse a microservicio pasa a una API).
        $destinationNodeId = DB::table('shipments')->where('id', $shipmentId)->value('destination_node_id');

        $hasAssigned = is_string($assignedNode) && $assignedNode !== '';
        $hasDestination = is_string($destinationNodeId) && $destinationNodeId !== '';

        if ($hasAssigned && $hasDestination && $destinationNodeId === $assignedNode) {
            return null;
        }

        return ProblemDetails::fromCode(
            ErrorCode::Forbidden,
            'This action is restricted to your assigned store node.',
        )->toResponse($request);
    }
}
