<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Query\GetPublicSurveyState\GetPublicSurveyState;
use Logistics\Tracking\Application\Query\GetPublicTrackingSnapshot\GetPublicTrackingSnapshot;
use Logistics\Tracking\Application\Query\GetPublicUnits\GetPublicUnits;
use Logistics\Tracking\Application\ReadModel\PublicLinkState;
use Logistics\Tracking\Application\ReadModel\PublicSurveyStateView;
use Logistics\Tracking\Application\ReadModel\PublicTrackingSnapshot;
use Logistics\Tracking\Application\ReadModel\PublicUnitView;
use Logistics\Tracking\Presentation\Http\PublicCacheHeaders;

/**
 * Página web PÚBLICA de rastreo por token: lo que abre el cliente desde el enlace que se le comparte.
 *
 * Renderiza estado, el mensaje "a N paradas", la ETA, el mapa con la posición del camión (Leaflet +
 * OpenStreetMap, sin API key) y el desglose de productos. Sin PII ni claves internas: toda la data viene
 * de los read models públicos (PublicTrackingView, PublicUnitView). Un token inexistente responde 404
 * genérico, sin revelar si el token existe.
 *
 * Es anónima y de solo lectura: no abre sesión ni fija cookies (la ruta va fuera de la pila "web"), así
 * que la respuesta 200 sale con `Cache-Control: public` + `ETag` y la puede guardar el borde.
 *
 * Presentation habla con Application vía el QueryBus; nunca toca Infrastructure ni el dominio.
 */
final class PublicTrackingPageController
{
    use PublicCacheHeaders;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request, string $token): Response
    {
        $snapshot = $this->queryBus->ask(new GetPublicTrackingSnapshot($token));
        $view = $snapshot instanceof PublicTrackingSnapshot ? $snapshot->view : null;

        if (!$snapshot instanceof PublicTrackingSnapshot || $view === null) {
            // Sin datos: ¿enlace vencido (TTL desde la entrega) o inexistente? El vencido recibe una página
            // amable en lenguaje de cliente (410 Gone); el resto, el 404 genérico de siempre.
            if ($snapshot instanceof PublicTrackingSnapshot && $snapshot->state === PublicLinkState::Expired) {
                return new Response(view('tracking::expired')->render(), 410);
            }

            return new Response(view('tracking::not-found')->render(), 404);
        }

        $units = $this->queryBus->ask(new GetPublicUnits($token));

        // Estado de la encuesta (K-06): se muestra el formulario solo si el envío está entregado y aún
        // no hay respuesta. Si la consulta falla de forma inesperada, se degrada a "no mostrar".
        $surveyState = $this->queryBus->ask(new GetPublicSurveyState($token));
        $canSurvey = $surveyState instanceof PublicSurveyStateView && $surveyState->canSubmit();
        $surveyRecorded = $surveyState instanceof PublicSurveyStateView && $surveyState->alreadySubmitted;

        // La vista se entrega como contenido del Response (queda en `original` para los tests) y se
        // renderiza una sola vez; el ETag es la huella del HTML final, que incluye productos y encuesta.
        $response = new Response(view('tracking::public', [
            'tracking' => $view->toArray(),
            'units' => array_map(
                static fn (PublicUnitView $unit): array => $unit->toArray(),
                is_array($units) ? $units : [],
            ),
            // El token de la URL se usa como action del formulario público de la encuesta. No es PII: es
            // la misma credencial por la que se llegó a la página.
            'publicToken' => $token,
            'canSurvey' => $canSurvey,
            'surveyRecorded' => $surveyRecorded,
        ]));

        return $this->withPublicCache($request, $response, hash('sha256', (string) $response->getContent()));
    }
}
