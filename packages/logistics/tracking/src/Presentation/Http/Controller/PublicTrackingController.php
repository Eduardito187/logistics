<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * max-age del cache HTTP del rastreo (M-5 de la auditoría de performance 2026-10-20). Corto a
     * propósito: la posición cambia seguido, pero 20 s absorben el polling de los clientes.
     */
    private const int PUBLIC_CACHE_MAX_AGE_SECONDS = 20;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $view = $this->queryBus->ask(new GetPublicTracking($token));

        if (!$view instanceof PublicTrackingView) {
            return $this->error('tracking_not_found', 'No tracking matches this token.', 404);
        }

        $response = $this->data($view->toArray());

        // Cache HTTP del rastreo público (M-5): es la página que los clientes pollean. Al no llevar PII
        // (ver PublicTrackingView) el CDN/navegador puede cachearla y absorber el polling sin pegar a la
        // base en cada hit. ETag + max-age corto; con `If-None-Match` coincidente se responde 304 vacío.
        $response->setEtag(md5((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(self::PUBLIC_CACHE_MAX_AGE_SECONDS);
        $response->isNotModified($request);

        return $response;
    }
}
