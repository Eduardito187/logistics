<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTracking;
use Logistics\Tracking\Application\ReadModel\PublicTrackingView;
use Logistics\Tracking\Presentation\Http\ApiResponder;

/**
 * Página PÚBLICA de rastreo por token (puerta /api/v1/channels/*).
 *
 * La consume la app de clientes, la web y el call center. El token opaco es la única credencial del
 * enlace; la respuesta no lleva PII (ver PublicTrackingView). Si el token no corresponde a ningún
 * seguimiento se devuelve 404 genérico, sin revelar si el token existe o no.
 *
 * Presentation habla con Application vía el QueryBus; nunca toca Infrastructure ni el dominio.
 */
final class PublicTrackingController
{
    use ApiResponder;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(string $token): JsonResponse
    {
        $view = $this->queryBus->ask(new GetPublicTracking($token));

        if (!$view instanceof PublicTrackingView) {
            return $this->error('tracking_not_found', 'No tracking matches this token.', 404);
        }

        return $this->data($view->toArray());
    }
}
