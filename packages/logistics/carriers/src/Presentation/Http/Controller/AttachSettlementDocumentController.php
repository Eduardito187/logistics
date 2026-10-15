<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Carriers\Application\Command\AttachSettlementDocument\AttachSettlementDocument;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Carriers\Presentation\Http\Request\AttachSettlementDocumentRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Adjunta un documento (factura, comprobante) a una liquidación (comando AttachSettlementDocument).
 *
 * El id de la liquidación llega por la ruta; el tipo y la referencia del documento por el cuerpo.
 */
final class AttachSettlementDocumentController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(AttachSettlementDocumentRequest $request, string $settlementId): JsonResponse
    {
        return $this->guard(function () use ($request, $settlementId): JsonResponse {
            $this->commands->dispatch(new AttachSettlementDocument(
                settlementId: $settlementId,
                documentType: $request->string('type')->toString(),
                documentReference: $request->string('reference')->toString(),
            ));

            return $this->accepted(['settlement_id' => $settlementId]);
        });
    }
}
