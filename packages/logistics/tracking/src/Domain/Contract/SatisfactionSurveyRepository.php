<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Contract;

use Logistics\Tracking\Domain\Model\SatisfactionSurvey;
use Logistics\Tracking\Domain\ValueObject\SurveyId;

/**
 * Puerto de persistencia de la encuesta de satisfacción. El dominio lo define; la infraestructura
 * (Eloquent) lo implementa.
 *
 * existsForShipment() respalda la regla de "una respuesta por envío": el handler la consulta antes de
 * grabar para rechazar duplicados (la unicidad dura la garantiza el índice único por shipment_id).
 */
interface SatisfactionSurveyRepository
{
    public function nextIdentity(): SurveyId;

    public function save(SatisfactionSurvey $survey): void;

    public function existsForShipment(string $shipmentId): bool;
}
