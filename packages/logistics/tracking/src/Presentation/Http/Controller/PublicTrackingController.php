<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Query\GetPublicTrackingSnapshot\GetPublicTrackingSnapshot;
use Logistics\Tracking\Application\ReadModel\PublicLinkState;
use Logistics\Tracking\Application\ReadModel\PublicTrackingSnapshot;
use Logistics\Tracking\Presentation\Http\ApiResponder;
use Logistics\Tracking\Presentation\Http\PublicCacheHeaders;

/**
 * Página PÚBLICA de rastreo por token (puerta /api/v1/channels/*).
 *
 * La consume la app de clientes, la web y el call center. El token opaco es la única credencial del
 * enlace; la respuesta no lleva PII (ver PublicTrackingView). Si el token no corresponde a ningún
 * seguimiento se devuelve 404 genérico, sin revelar si el token existe o no.
 *
 * Camino caliente del read-side público: UNA consulta al bus que sale del caché de lectura (Valkey);
 * en el caso común no toca la base. Las respuestas de error (404/410) no se marcan como públicas.
 *
 * Presentation habla con Application vía el QueryBus; nunca toca Infrastructure ni el dominio.
 */
final class PublicTrackingController
{
    use ApiResponder;
    use PublicCacheHeaders;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $snapshot = $this->queryBus->ask(new GetPublicTrackingSnapshot($token));
        $view = $snapshot instanceof PublicTrackingSnapshot ? $snapshot->view : null;

        if (!$snapshot instanceof PublicTrackingSnapshot || $view === null) {
            // Enlace vencido (TTL desde la entrega): 410 con código estable; inexistente: 404 genérico.
            if ($snapshot instanceof PublicTrackingSnapshot && $snapshot->state === PublicLinkState::Expired) {
                return $this->error('tracking_link_expired', 'This tracking link has expired.', 410);
            }

            return $this->error('tracking_not_found', 'No tracking matches this token.', 404);
        }

        $response = $this->data($view->toArray());

        // Cache HTTP del rastreo público (M-5): es la respuesta que los clientes pollean. El ETag es la
        // huella que ya viene en la foto cacheada: no se vuelve a hashear el cuerpo en cada request.
        return $this->withPublicCache(
            $request,
            $response,
            $snapshot->etag ?? hash('sha256', (string) $response->getContent()),
        );
    }
}
