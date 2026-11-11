<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTracking;
use Logistics\Tracking\Application\Query\GetPublicUnits\GetPublicUnits;
use Logistics\Tracking\Application\ReadModel\PublicTrackingView;
use Logistics\Tracking\Application\ReadModel\PublicUnitView;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Seguí tu pedido» — página PÚBLICA de rastreo por token, en la app Vue+Inertia (primera superficie de
 * la nueva SPA orientada al cliente). Misma fuente de datos y mismas garantías que la página Blade
 * existente (read models públicos SIN PII, 404 genérico para token inexistente), pero renderizada en Vue.
 *
 * Presentation habla con Application vía el QueryBus; nunca toca Infrastructure ni el dominio.
 */
final class TrackController
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(string $token): InertiaResponse | Response
    {
        $view = $this->queryBus->ask(new GetPublicTracking($token));

        if (!$view instanceof PublicTrackingView) {
            // Mismo criterio que la página Blade: 404 genérico, sin revelar si el token existe.
            return Inertia::render('Track/NotFound')->toResponse(request())->setStatusCode(404);
        }

        $units = $this->queryBus->ask(new GetPublicUnits($token));

        return Inertia::render('Track/Show', [
            'tracking' => $view->toArray(),
            'units' => array_map(
                static fn (PublicUnitView $unit): array => $unit->toArray(),
                is_array($units) ? $units : [],
            ),
        ]);
    }
}
