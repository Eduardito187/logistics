<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTracking;
use Logistics\Tracking\Application\Query\GetPublicUnits\GetPublicUnits;
use Logistics\Tracking\Application\ReadModel\PublicTrackingView;
use Logistics\Tracking\Application\ReadModel\PublicUnitView;

/**
 * Página web PÚBLICA de rastreo por token: lo que abre el cliente desde el enlace que se le comparte.
 *
 * Renderiza estado, el mensaje "a N paradas", la ETA, el mapa con la posición del camión (Leaflet +
 * OpenStreetMap, sin API key) y el desglose de bultos. Sin PII ni claves internas: toda la data viene
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

        return view('tracking::public', [
            'tracking' => $view->toArray(),
            'units' => array_map(
                static fn (PublicUnitView $unit): array => $unit->toArray(),
                is_array($units) ? $units : [],
            ),
        ]);
    }
}
