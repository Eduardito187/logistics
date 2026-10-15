<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\Exception\DomainException;
use Logistics\Tracking\Application\Command\RecordSatisfactionSurvey\RecordSatisfactionSurvey;
use Logistics\Tracking\Application\Query\GetPublicSurveyState\GetPublicSurveyState;
use Logistics\Tracking\Application\ReadModel\PublicSurveyStateView;
use Logistics\Tracking\Presentation\Http\ApiResponder;
use Logistics\Tracking\Presentation\Http\Request\RecordSatisfactionSurveyRequest;

/**
 * Endpoint PÚBLICO (por token) para registrar la encuesta de satisfacción post-entrega (K-06).
 *
 * La única credencial es el token opaco de la URL; el rate limit lo pone el middleware de la ruta. No
 * recibe ni devuelve PII. El estado de elegibilidad se consulta primero (vía QueryBus) para mapear el
 * HTTP correcto sin parsear mensajes del dominio:
 *   - token inválido/inexistente → 404 genérico (no revela si el token existe).
 *   - envío no entregado todavía → 422.
 *   - ya calificado (una por envío)→ 409.
 * El registro real lo hace el CommandBus (transaccional + outbox); el dominio reconfirma invariantes.
 *
 * Presentation habla con Application vía los buses; nunca toca Infrastructure ni el dominio.
 */
final class RecordSatisfactionSurveyController
{
    use ApiResponder;

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(RecordSatisfactionSurveyRequest $request, string $token): JsonResponse
    {
        $state = $this->queryBus->ask(new GetPublicSurveyState($token));

        if (!$state instanceof PublicSurveyStateView) {
            return $this->error('tracking_not_found', 'No tracking matches this token.', 404);
        }

        if (!$state->eligible) {
            return $this->error('survey_not_available', 'The shipment is not delivered yet.', 422);
        }

        if ($state->alreadySubmitted) {
            return $this->error('survey_already_recorded', 'This shipment was already rated.', 409);
        }

        try {
            $this->commandBus->dispatch(new RecordSatisfactionSurvey(
                token: $token,
                rating: $request->rating(),
                comment: $request->comment(),
                reason: $request->reason(),
            ));
        } catch (DomainException $exception) {
            // Carrera o reconfirmación de invariante del dominio: rechazo idempotente.
            return $this->error('survey_rejected', $exception->getMessage(), 409);
        }

        return $this->data(['recorded' => true], 201);
    }
}
