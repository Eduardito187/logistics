<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Logistics\Execution\Application\Contract\EvidenceStorage;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;
use Logistics\Execution\Presentation\Http\Request\ProofOfDeliveryRequest;
use Logistics\Execution\Presentation\Http\Support\ScanCommandFactory;
use Logistics\Execution\Presentation\Http\Support\StoreNodeScope;

/**
 * POST /app/deliveries/proof-of-delivery y POST /app/deliveries/{shipmentId}/complete — registra la prueba
 * de entrega y cierra la entrega como entregada (puerta app).
 *
 * POD en LOTE: `unit_ids:[…]` cierra la entrega con todos esos bultos en UNA sola confirmación/firma/GPS
 * (antes era un POST por bulto). Vincula las evidencias ya subidas (`photo_paths`, `evidence_ids`). GPS
 * opcional (`lat`/`lng` o `location:{lat,lng,accuracy_m,captured_at}`); 0,0 nunca se guarda como posición.
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (cargada o en tránsito).
 *
 * Alcance por tienda (server-side): ver StoreNodeScope (403 RFC 7807 si la tienda no es la del envío).
 */
final class RecordProofOfDeliveryController extends ExecutionController
{
    public function __invoke(ProofOfDeliveryRequest $request, EvidenceStorage $storage): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $shipmentId = (string) $data['shipment_id'];

        if (!StoreNodeScope::allows($request->user(), $shipmentId)) {
            return ProblemDetails::fromCode(ErrorCode::Forbidden, StoreNodeScope::DENIED_DETAIL)->toResponse($request);
        }

        // Foto INLINE (opcional): si la app mandó el archivo en el MISMO request (multipart `photo`), se
        // guarda acá —mismo disco de evidencia que POST /app/deliveries/photos— y se liga como `photo_path`
        // en la misma operación. Si la subida inline falla, el POD NO se bloquea (offline-first): la entrega
        // se cierra igual y la foto se liga aparte después. Así conviven AMBOS flujos (inline y asíncrono).
        $photo = $request->file('photo');
        if ($photo instanceof UploadedFile) {
            try {
                $stored = $storage->store(EvidenceKind::ProofOfDelivery, $shipmentId, $photo, $this->idempotencyKey($request));
                $data['photo_path'] = $stored->path;
            } catch (ExecutionException $exception) {
                report($exception);
            }
        }

        $this->commandBus->dispatch(ScanCommandFactory::proofOfDelivery(
            $data,
            $request->user(),
            $this->deviceId($request),
            $this->userId($request),
            $this->idempotencyKey($request),
        ));

        return $this->deliveryStateResponse($shipmentId);
    }
}
