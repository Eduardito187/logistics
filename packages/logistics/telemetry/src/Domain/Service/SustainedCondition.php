<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Service;

use DateTimeImmutable;

/**
 * Lleva la cuenta de una condición que debe SOSTENERSE un tiempo mínimo antes de alertar (exceso de
 * velocidad, desvío de ruta). Servicio de dominio puro: el estado (`since`) lo persiste el orquestador entre
 * lotes; acá solo se decide, lectura a lectura, si la condición sigue, se corta o ya cumplió el mínimo.
 *
 *  - condición falsa → se corta: `since` vuelve a null y no dispara;
 *  - condición verdadera → `since` arranca en esta lectura (si no venía) y dispara cuando
 *    `at - since >= minSeconds`.
 *
 * Con `minSeconds = 0` dispara en la primera lectura que cumple (comportamiento instantáneo histórico).
 */
final readonly class SustainedCondition
{
    /**
     * @return array{since: DateTimeImmutable|null, sustained_seconds: int, fire: bool}
     */
    public function step(?DateTimeImmutable $since, bool $holds, DateTimeImmutable $at, int $minSeconds): array
    {
        if (!$holds) {
            return ['since' => null, 'sustained_seconds' => 0, 'fire' => false];
        }

        // Una lectura anterior al inicio registrado adelanta el inicio: la condición ya regía antes.
        $start = ($since === null || $at < $since) ? $at : $since;
        $sustained = max(0, $at->getTimestamp() - $start->getTimestamp());

        return [
            'since' => $start,
            'sustained_seconds' => $sustained,
            'fire' => $sustained >= max(0, $minSeconds),
        ];
    }
}
