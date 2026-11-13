<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Service;

use Logistics\Promise\Domain\ValueObject\CustomerTimePreference;
use Logistics\Promise\Domain\ValueObject\WindowCandidate;
use Logistics\Promise\Domain\ValueObject\WindowSelectionPolicy;

/**
 * Servicio de dominio PURO que elige la mejor ventana de entrega de un día cuando el cliente solo fijó la
 * fecha (Módulo 1 del roadmap). No toca capacity ni shipments: recibe las candidatas ya resueltas (cupo
 * por franja), la preferencia del cliente y los pesos, y devuelve la ganadora o null si no hay ninguna
 * factible (el caso de uso decide el escalamiento a la cola "Unslotted").
 *
 * Puntaje de cada candidata factible (cupo >= 1 entrega):
 *
 *   score = w_pref  * preferencia(c)                 // 0..1, honra la preferencia horaria (blanda, alta)
 *         + w_bal   * (cupo_c / cupo_max)             // balanceo: prefiere la franja con más cupo libre
 *         + w_early * (1 − índice/n)                  // sesgo a franjas tempranas del día (desempate suave)
 *
 * Determinista: ordena las candidatas por hora de inicio (las sin borde van al final) y, a igual puntaje,
 * desempata por la etiqueta de franja, de modo que la elección no dependa del orden de entrada.
 */
final readonly class WindowSelector
{
    /**
     * Elige la ventana ganadora entre las candidatas, o null si ninguna tiene cupo.
     *
     * @param list<WindowCandidate> $candidates
     */
    public function select(array $candidates, CustomerTimePreference $preference, WindowSelectionPolicy $policy): ?WindowCandidate
    {
        $feasible = array_values(array_filter(
            $candidates,
            static fn (WindowCandidate $candidate): bool => $candidate->isFeasible(),
        ));
        if ($feasible === []) {
            return null;
        }

        // Orden estable por hora de inicio (sin borde = al final), desempate por etiqueta: así el índice de
        // "temprano" y el desempate final son independientes del orden con que llegaron las candidatas.
        usort($feasible, static function (WindowCandidate $a, WindowCandidate $b): int {
            return [$a->startMinute ?? PHP_INT_MAX, $a->timeSlot] <=> [$b->startMinute ?? PHP_INT_MAX, $b->timeSlot];
        });

        $maxAvailable = 0.0;
        foreach ($feasible as $candidate) {
            $maxAvailable = max($maxAvailable, $candidate->available);
        }

        $count = count($feasible);
        $best = null;
        $bestScore = -1.0;

        foreach ($feasible as $index => $candidate) {
            $preferenceScore = $preference->score($candidate->startMinute, $candidate->endMinute);
            $balanceScore = $maxAvailable > 0.0 ? $candidate->available / $maxAvailable : 0.0;
            $earlyScore = $count > 1 ? 1.0 - $index / ($count - 1) : 1.0;

            $score = $policy->preferenceWeight * $preferenceScore
                + $policy->balanceWeight * $balanceScore
                + $policy->earlyWeight * $earlyScore;

            // Estricto mayor: a igual puntaje gana la primera en el orden estable (franja más temprana /
            // etiqueta menor), lo que fija el desempate de forma determinista.
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }
}
