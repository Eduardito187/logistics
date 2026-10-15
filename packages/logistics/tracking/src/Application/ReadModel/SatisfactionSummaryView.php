<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

/**
 * Resumen de satisfacción post-entrega para el back office (K-06): volumen, promedio, NPS y los
 * comentarios más recientes.
 *
 * El NPS se adapta a la escala 1-5: promotor = 5, pasivo = 4, detractor = 1-3; NPS = %promotores −
 * %detractores (rango −100..100). Null cuando todavía no hay respuestas. Si se cachea, se cachea su
 * toArray() (regla 7). Los comentarios no llevan PII: son el texto que el cliente escribió sobre su
 * propia entrega, su calificación y la fecha.
 */
final readonly class SatisfactionSummaryView
{
    /**
     * @param list<array{rating: int, comment: string, reason: string|null, submitted_at: string}> $recentComments
     */
    public function __construct(
        public int $responseCount,
        public ?float $averageRating,
        public ?int $nps,
        public int $promoters,
        public int $passives,
        public int $detractors,
        public array $recentComments,
    ) {}

    /**
     * @return array{response_count: int, average_rating: float|null, nps: int|null, promoters: int, passives: int, detractors: int, recent_comments: list<array{rating: int, comment: string, reason: string|null, submitted_at: string}>}
     */
    public function toArray(): array
    {
        return [
            'response_count' => $this->responseCount,
            'average_rating' => $this->averageRating,
            'nps' => $this->nps,
            'promoters' => $this->promoters,
            'passives' => $this->passives,
            'detractors' => $this->detractors,
            'recent_comments' => $this->recentComments,
        ];
    }
}
