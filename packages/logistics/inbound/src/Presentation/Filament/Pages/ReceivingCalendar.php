<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Logistics\Inbound\Application\Query\GetReceivingDockCalendar\GetReceivingDockCalendar;
use Logistics\Inbound\Application\ReadModel\ReceivingDockDayView;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Calendario de andén de recepción (grupo Operations): espejo del DispatchCalendar, pero del lado de
 * ENTRADA. Muestra los SIETE días de la semana con, por franja, cuántas citas de recepción hay agendadas.
 *
 * Los números salen del lado de lectura CQRS (QueryBus → GetReceivingDockCalendar). Todo texto visible en
 * inglés (regla 5); comentarios en español (regla 6).
 */
final class ReceivingCalendar extends Page
{
    use OfficeOnly;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-calendar';

    protected static ?int $navigationSort = 31;

    protected string $view = 'logistics-inbound::filament.pages.receiving-calendar';

    /** Lunes de la semana que se está mirando, en 'Y-m-d'. Lo mueven los botones anterior/siguiente. */
    public string $weekStart = '';

    public function mount(): void
    {
        if ($this->weekStart === '') {
            $this->weekStart = $this->mondayOf(date('Y-m-d'));
        }
    }

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Receiving calendar');
    }

    public function getTitle(): string
    {
        return __('Receiving calendar');
    }

    /**
     * Avanza o retrocede la semana mirada.
     */
    public function shiftWeek(int $weeks): void
    {
        $timestamp = strtotime($this->weekStart.' '.($weeks * 7).' day');
        if ($timestamp === false) {
            return;
        }
        $this->weekStart = $this->mondayOf(date('Y-m-d', $timestamp));
    }

    public function goToThisWeek(): void
    {
        $this->weekStart = $this->mondayOf(date('Y-m-d'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'weekStart' => $this->weekStart,
            'weekEnd' => $this->weekEnd(),
            'week' => $this->buildWeek(),
        ];
    }

    /**
     * Construye los siete días de la semana mirada con sus citas por franja.
     *
     * @return list<array{date: string, weekday_label: string, is_today: bool, appointments: int, slots: list<array{time_slot: string, appointments: int}>}>
     */
    private function buildWeek(): array
    {
        /** @var QueryBus $queries */
        $queries = app(QueryBus::class);

        /** @var list<ReceivingDockDayView> $calendar */
        $calendar = $queries->ask(new GetReceivingDockCalendar(
            fromDate: $this->mondayOf($this->weekStart),
            days: 7,
        ));

        $today = date('Y-m-d');
        $names = $this->weekdayShortNames();

        $days = [];
        foreach ($calendar as $day) {
            $timestamp = strtotime($day->date);
            $weekday = $timestamp === false ? 1 : (int) date('N', $timestamp);
            $days[] = [
                'date' => $day->date,
                'weekday_label' => $names[$weekday] ?? (string) $weekday,
                'is_today' => $day->date === $today,
                'appointments' => $day->appointments,
                'slots' => $day->slots,
            ];
        }

        return $days;
    }

    /**
     * @return array<int, string>
     */
    private function weekdayShortNames(): array
    {
        return [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];
    }

    /**
     * Lunes de la semana que contiene la fecha dada, en 'Y-m-d'. Cae a hoy si la fecha es inválida.
     */
    private function mondayOf(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            $timestamp = time();
        }
        $weekday = (int) date('N', $timestamp);
        $monday = strtotime('-'.($weekday - 1).' day', $timestamp);

        return date('Y-m-d', $monday === false ? $timestamp : $monday);
    }

    /**
     * Domingo de la semana mirada, en 'Y-m-d'.
     */
    private function weekEnd(): string
    {
        $timestamp = strtotime($this->mondayOf($this->weekStart).' 6 day');

        return $timestamp === false ? $this->weekStart : date('Y-m-d', $timestamp);
    }
}
