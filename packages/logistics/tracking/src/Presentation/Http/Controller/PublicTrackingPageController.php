<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Query\GetPublicSurveyState\GetPublicSurveyState;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTracking;
use Logistics\Tracking\Application\Query\GetPublicUnits\GetPublicUnits;
use Logistics\Tracking\Application\ReadModel\PublicSurveyStateView;
use Logistics\Tracking\Application\ReadModel\PublicTrackingView;
use Logistics\Tracking\Application\ReadModel\PublicUnitView;

/**
 * Página web PÚBLICA de rastreo por token: lo que abre el cliente desde el enlace que se le comparte.
 *
 * Renderiza estado, el mensaje "a N paradas", la ETA, el mapa con la posición del camión (Leaflet +
 * OpenStreetMap, sin API key) y el desglose de productos. Sin PII ni claves internas: toda la data viene
 * de los read models públicos (PublicTrackingView, PublicUnitView). Un token inexistente responde 404
 * genérico, sin revelar si el token existe.
 *
 * Presentation habla con Application vía el QueryBus; nunca toca Infrastructure ni el dominio.
 */
final class PublicTrackingPageController
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(string $token): View | Response
    {
        $view = $this->queryBus->ask(new GetPublicTracking($token));

        if (!$view instanceof PublicTrackingView) {
            return new Response(view('tracking::not-found')->render(), 404);
        }

        $units = $this->queryBus->ask(new GetPublicUnits($token));

        // Estado de la encuesta (K-06): se muestra el formulario solo si el envío está entregado y aún
        // no hay respuesta. Si la consulta falla de forma inesperada, se degrada a "no mostrar".
        $surveyState = $this->queryBus->ask(new GetPublicSurveyState($token));
        $canSurvey = $surveyState instanceof PublicSurveyStateView && $surveyState->canSubmit();
        $surveyRecorded = $surveyState instanceof PublicSurveyStateView && $surveyState->alreadySubmitted;

        return view('tracking::public', [
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
        ]);
    }
}
