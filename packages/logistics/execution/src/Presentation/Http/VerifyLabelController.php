<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Query\VerifyLabel\VerifyLabel;
use Logistics\Execution\Application\ReadModel\LabelVerificationView;
use Logistics\Execution\Presentation\Http\Request\VerifyLabelRequest;

/**
 * POST /app — contraverificación del servidor de la firma de una etiqueta QR (puerta app).
 *
 * La app Flutter ya verifica offline con el bundle de claves públicas; este endpoint repite la
 * verificación en el servidor para los casos dudosos. Devuelve el resultado como un hecho de negocio
 * (200 con valid/result), no como error: una etiqueta falsa o editada no es una falla del sistema.
 */
final class VerifyLabelController extends ExecutionController
{
    public function __invoke(VerifyLabelRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var LabelVerificationView $view */
        $view = $this->queryBus->ask(new VerifyLabel(
            canonicalPayload: (string) $data['payload'],
            signature: (string) $data['signature'],
            keyId: (int) $data['key_id'],
        ));

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
