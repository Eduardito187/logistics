<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetSatisfactionSummary;

use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Tracking\Application\ReadModel\SatisfactionSummaryView;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Throwable;

/**
 * Compone el resumen de satisfacción leyendo la tabla del read model en SQL (agregación eficiente, igual
 * patrón que los resúmenes de costos de fleetops). Degrada a un resumen vacío si la tabla no existe
 * todavía, para no tumbar el tablero mientras la migración no se aplicó.
 *
 * @implements QueryHandler<GetSatisfactionSummary, SatisfactionSummaryView>
 */
final class GetSatisfactionSummaryHandler implements QueryHandler
{
    private const string TABLE = 'tracking_satisfaction_surveys';

    public function handle(Query $query): SatisfactionSummaryView
    {
        if (!$query instanceof GetSatisfactionSummary) {
            throw TrackingException::because('GetSatisfactionSummaryHandler solo maneja GetSatisfactionSummary');
        }

        try {
            return $this->summarize($query->recentLimit);
        } catch (Throwable) {
            return new SatisfactionSummaryView(0, null, null, 0, 0, 0, []);
        }
    }

    private function summarize(int $recentLimit): SatisfactionSummaryView
    {
        /** @var object{total: int|string|null, avg: float|string|null, promoters: int|string|null, passives: int|string|null, detractors: int|string|null}|null $row */
        $row = DB::table(self::TABLE)
            ->selectRaw('count(*) as total')
            ->selectRaw('avg(rating) as avg')
            ->selectRaw('count(*) filter (where rating >= 5) as promoters')
            ->selectRaw('count(*) filter (where rating = 4) as passives')
            ->selectRaw('count(*) filter (where rating <= 3) as detractors')
            ->first();

        if ($row === null) {
            return new SatisfactionSummaryView(0, null, null, 0, 0, 0, []);
        }

        $total = (int) $row->total;
        if ($total === 0) {
            return new SatisfactionSummaryView(0, null, null, 0, 0, 0, []);
        }

        $promoters = (int) $row->promoters;
        $passives = (int) $row->passives;
        $detractors = (int) $row->detractors;
        $average = $row->avg !== null ? round((float) $row->avg, 2) : null;

        // NPS adaptado a 1-5: %promotores − %detractores, redondeado a entero (−100..100).
        $nps = (int) round((($promoters - $detractors) / $total) * 100);

        return new SatisfactionSummaryView(
            $total,
            $average,
            $nps,
            $promoters,
            $passives,
            $detractors,
            $this->recentComments($recentLimit),
        );
    }

    /**
     * Últimos comentarios no vacíos, para que operaciones lea la voz del cliente de un vistazo.
     *
     * @return list<array{rating: int, comment: string, reason: string|null, submitted_at: string}>
     */
    private function recentComments(int $recentLimit): array
    {
        $limit = max(1, min($recentLimit, 50));

        $rows = DB::table(self::TABLE)
            ->select(['rating', 'comment', 'reason', 'created_at'])
            ->whereNotNull('comment')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $comments = [];
        foreach ($rows as $row) {
            /** @var object{rating: int|string, comment: string|null, reason: string|null, created_at: string|null} $row */
            $comment = $row->comment;
            if ($comment === null || trim($comment) === '') {
                continue;
            }

            $comments[] = [
                'rating' => (int) $row->rating,
                'comment' => $comment,
                'reason' => $row->reason,
                'submitted_at' => (string) ($row->created_at ?? ''),
            ];
        }

        return $comments;
    }
}
