<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\AcknowledgeAlert\AcknowledgeAlert;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * Reconoce (acknowledge) una alerta despachando el comando AcknowledgeAlert.
 *
 * Quién la reconoce (`by`) sale del usuario autenticado (su email); si no hay uno identificable,
 * se registra como 'system'. El id de la alerta llega por la ruta.
 */
final class AcknowledgeAlertController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(Request $request, string $alertId): JsonResponse
    {
        return $this->guard(function () use ($request, $alertId): JsonResponse {
            $this->commands->dispatch(new AcknowledgeAlert(
                alertId: $alertId,
                by: $this->resolveActor($request),
            ));

            return $this->accepted(['alert_id' => $alertId]);
        });
    }

    /**
     * Resuelve el actor que reconoce la alerta: el email del usuario autenticado o 'system'.
     */
    private function resolveActor(Request $request): string
    {
        $user = $request->user();
        $email = $user instanceof User ? $user->getAttribute('email') : null;

        return is_string($email) && $email !== '' ? $email : 'system';
    }
}
