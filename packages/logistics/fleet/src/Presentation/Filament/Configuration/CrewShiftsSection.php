<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Configuration;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Logistics\Fleet\Domain\Contract\ShiftRulesPolicy;
use Logistics\Fleet\Domain\ValueObject\ShiftRules;
use Logistics\Fleet\Infrastructure\Settings\SettingsShiftRulesPolicy;
use Logistics\Settings\Presentation\Filament\Configuration\ConfigurationSection;
use Logistics\Shared\Presentation\Filament\Concerns\ResolvesUserRoles;

/**
 * Reglas de turnos y descansos de las tripulaciones (jornada máxima, ventana horaria, descanso mínimo).
 * La ventana se edita como hora del día (HH:MM) y se guarda en minutos desde medianoche; el VO ShiftRules
 * valida (fin posterior al inicio, jornada > 0) antes de publicar.
 */
final class CrewShiftsSection extends ConfigurationSection
{
    use ResolvesUserRoles;

    public static function slug(): string
    {
        return 'fleet-shifts';
    }

    public static function tab(): string
    {
        return 'Fleet';
    }

    public static function tabSort(): int
    {
        return 20;
    }

    public static function label(): string
    {
        return 'Crew shifts';
    }

    public static function sort(): int
    {
        return 20;
    }

    public static function canAccess(): bool
    {
        return self::authUserHasAnyRoleValue('admin', 'supervisor');
    }

    public function components(): array
    {
        return [
            Section::make(__('Shift window'))
                ->collapsible()
                ->schema([
                    TimePicker::make('earliest_start')
                        ->label(self::scoped('Earliest start'))
                        ->seconds(false)
                        ->required()
                        ->helperText(__('No shift may start before this time.')),
                    TimePicker::make('latest_end')
                        ->label(self::scoped('Latest end'))
                        ->seconds(false)
                        ->required()
                        ->after('earliest_start')
                        ->helperText(__('No shift may end after this time.')),
                ]),
            Section::make(__('Working time'))
                ->collapsible()
                ->schema([
                    TextInput::make('max_daily_minutes')
                        ->label(self::scoped('Maximum daily work (minutes)'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1440)
                        ->required(),
                    TextInput::make('min_rest_minutes')
                        ->label(self::scoped('Minimum rest between shifts (minutes)'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(1440)
                        ->required(),
                ]),
        ];
    }

    public function state(): array
    {
        $rules = app(ShiftRulesPolicy::class)->rules();

        return [
            'earliest_start' => self::toTime($rules->earliestStartMinute),
            'latest_end' => self::toTime($rules->latestEndMinute),
            'max_daily_minutes' => $rules->maxDailyMinutes,
            'min_rest_minutes' => $rules->minRestMinutes,
        ];
    }

    public function save(array $data): void
    {
        // El VO valida la combinación (lanza FleetException, que la página muestra sin guardar).
        $rules = ShiftRules::of(
            self::int($data, 'max_daily_minutes', 0),
            self::toMinute(self::str($data, 'earliest_start')),
            self::toMinute(self::str($data, 'latest_end')),
            self::int($data, 'min_rest_minutes', 0),
        );

        $this->publish(SettingsShiftRulesPolicy::SETTING_KEY, [
            'max_daily_minutes' => $rules->maxDailyMinutes,
            'earliest_start_minute' => $rules->earliestStartMinute,
            'latest_end_minute' => $rules->latestEndMinute,
            'min_rest_minutes' => $rules->minRestMinutes,
        ]);
    }

    private static function toTime(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    /** "HH:MM" o "HH:MM:SS" → minutos desde medianoche. */
    private static function toMinute(string $time): int
    {
        [$hours, $minutes] = array_map('intval', array_pad(explode(':', $time), 2, '0'));

        return $hours * 60 + $minutes;
    }
}
