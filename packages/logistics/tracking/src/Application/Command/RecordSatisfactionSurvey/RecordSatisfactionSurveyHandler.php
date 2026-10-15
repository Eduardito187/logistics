<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\RecordSatisfactionSurvey;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Domain\Contract\SatisfactionSurveyRepository;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\SatisfactionSurvey;
use Logistics\Tracking\Domain\ValueObject\Rating;
use Logistics\Tracking\Domain\ValueObject\SurveyReason;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Graba la encuesta de satisfacción del cliente (K-06) y libera el hecho al outbox.
 *
 * Resuelve el seguimiento por el token público y aplica las invariantes del caso de uso, que son
 * también la última línea de defensa ante una carrera (el controlador ya precheckeó el estado):
 *  - token inválido o inexistente ⇒ TrackingException (el controlador devuelve 404 genérico).
 *  - el envío debe estar ENTREGADO para poder calificarlo ⇒ si no, TrackingException (422).
 *  - una sola respuesta por envío: si ya existe, TrackingException (409), sin reemplazar la original.
 *
 * El save del repo y el append al outbox son atómicos: el CommandBus los envuelve en una transacción.
 *
 * @implements CommandHandler<RecordSatisfactionSurvey>
 */
final class RecordSatisfactionSurveyHandler implements CommandHandler
{
    public function __construct(
        private readonly SatisfactionSurveyRepository $surveys,
        private readonly TrackingRepository $trackings,
        private readonly RecordDomainEvents $recordEvents,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordSatisfactionSurvey) {
            throw TrackingException::because('RecordSatisfactionSurveyHandler solo maneja RecordSatisfactionSurvey');
        }

        $token = TrackingToken::fromString($command->token);

        $tracking = $this->trackings->findByToken($token);
        if ($tracking === null) {
            throw TrackingException::because('no existe seguimiento para ese token');
        }

        if ($tracking->status() !== VisibleStatus::Delivered) {
            throw TrackingException::because('solo se puede calificar un envío entregado');
        }

        $shipmentId = $tracking->shipmentId();
        if ($this->surveys->existsForShipment($shipmentId)) {
            throw TrackingException::because('este envío ya tiene una respuesta de encuesta');
        }

        $survey = SatisfactionSurvey::record(
            $this->surveys->nextIdentity(),
            $shipmentId,
            $token->toString(),
            Rating::of($command->rating),
            $command->comment,
            $this->parseReason($command->reason),
            $this->clock->now(),
        );

        $this->surveys->save($survey);

        $this->recordEvents->record($survey->releaseEvents(), 'tracking.satisfaction_survey');
    }

    /**
     * Convierte el motivo crudo (si llega) al enum; un valor desconocido se descarta como null en vez
     * de reventar: el motivo es un dato auxiliar, no debe tumbar la calificación.
     */
    private function parseReason(?string $reason): ?SurveyReason
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        return SurveyReason::tryFrom($reason);
    }
}
