<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Inbound\Application\Query\GetAsn\GetAsn;
use Logistics\Inbound\Application\ReadModel\AsnView;
use Logistics\Inbound\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve un ASN por su id (GET /v1/inbound/asn/{asnId}), o 404 si no existe.
 */
final class GetAsnController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $asnId): JsonResponse
    {
        return $this->guard(function () use ($asnId): JsonResponse {
            /** @var AsnView|null $asn */
            $asn = $this->queries->ask(new GetAsn(asnId: $asnId));

            if ($asn === null) {
                return $this->error('not_found', 'ASN not found.', 404);
            }

            return $this->data($asn->toArray());
        });
    }
}
