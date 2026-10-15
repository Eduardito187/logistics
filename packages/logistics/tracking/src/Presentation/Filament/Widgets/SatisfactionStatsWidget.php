<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Query\GetSatisfactionSummary\GetSatisfactionSummary;
use Logistics\Tracking\Application\ReadModel\SatisfactionSummaryView;

/**
 * Tarjetas de satisfacción post-entrega (K-06) para la cabecera del listado de encuestas: NPS, promedio
 * y volumen de respuestas, además del reparto promotores/pasivos/detractores.
 *
 * Lee el resumen por el QueryBus (ReadModel optimizado), nunca Infrastructure directo. Todo texto en
 * inglés vía __().
 */
final class SatisfactionStatsWidget extends StatsOverviewWidget
{
    // Tres tarjetas por fila: NPS, promedio y respuestas quedan parejas.
    protected int | array | null $columns = 3;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $summary = app(QueryBus::class)->ask(new GetSatisfactionSummary(10));
        if (!$summary instanceof SatisfactionSummaryView) {
            $summary = new SatisfactionSummaryView(0, null, null, 0, 0, 0, []);
        }

        return [
            Stat::make(__('NPS'), $summary->nps === null ? '—' : (string) $summary->nps)
                ->description(__('Promoters minus detractors'))
                ->descriptionIcon('heroicon-m-face-smile')
                ->color($this->npsColor($summary->nps)),

            Stat::make(__('Average rating'), $summary->averageRating === null
                ? '—'
                : number_format($summary->averageRating, 2).' / 5')
                ->description(__(':promoters promoters · :passives passive · :detractors detractors', [
                    'promoters' => $summary->promoters,
                    'passives' => $summary->passives,
                    'detractors' => $summary->detractors,
                ]))
                ->descriptionIcon('heroicon-m-star')
                ->color('warning'),

            Stat::make(__('Responses'), (string) $summary->responseCount)
                ->description(__('Delivered shipments rated by customers'))
                ->descriptionIcon('heroicon-m-inbox-stack')
                ->color('primary'),
        ];
    }

    /**
     * Color del NPS: verde si es positivo, rojo si es negativo, gris si no hay datos.
     */
    private function npsColor(?int $nps): string
    {
        if ($nps === null) {
            return 'gray';
        }

        return $nps >= 0 ? 'success' : 'danger';
    }
}
