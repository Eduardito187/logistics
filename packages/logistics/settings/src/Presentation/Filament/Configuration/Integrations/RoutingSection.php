<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Configuration\Integrations;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Logistics\Settings\Infrastructure\Integration\IntegrationKeys;

/**
 * Integración de ruteo por carretera: motores OSRM y VROOM y el backend del optimizador.
 * Un campo vacío vuelve al valor del entorno.
 */
final class RoutingSection extends IntegrationSection
{
    public static function slug(): string
    {
        return 'integrations-routing';
    }

    public static function label(): string
    {
        return 'Routing';
    }

    public static function sort(): int
    {
        return 10;
    }

    public function components(): array
    {
        return [
            Section::make(__('General Configuration'))
                ->collapsible()
                ->schema([
                    self::enabledToggle('When off, the platform uses the environment defaults.'),
                    Select::make('backend')
                        ->label(self::scoped('Routing backend'))
                        ->native(false)
                        ->options([
                            'builtin' => __('Built-in (Clarke-Wright + 2-opt)'),
                            'osrm_trip' => __('OSRM trip'),
                            'vroom' => __('VROOM'),
                        ])
                        ->required(),
                ]),
            Section::make(__('OSRM'))
                ->collapsible()
                ->schema([
                    TextInput::make('osrm_host')
                        ->label(self::scoped('OSRM host'))
                        ->url()
                        ->maxLength(255)
                        ->helperText(__('Base URL of the OSRM routing server, e.g. https://osrm.example.com.')),
                    TextInput::make('osrm_timeout')
                        ->label(self::scoped('OSRM timeout (seconds)'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(120),
                ]),
            Section::make(__('VROOM'))
                ->collapsible()
                ->schema([
                    TextInput::make('vroom_url')
                        ->label(self::scoped('VROOM URL'))
                        ->url()
                        ->maxLength(255)
                        ->helperText(__('Optional VROOM solver endpoint, e.g. https://vroom.example.com. Used only with the VROOM backend.')),
                ]),
        ];
    }

    public function state(): array
    {
        return [
            'enabled' => self::config()->enabled(IntegrationKeys::ROUTING, true),
            'backend' => (string) config('logistics-planning.routing.backend', 'builtin'),
            'osrm_host' => (string) config('logistics-planning.osrm_host', ''),
            'osrm_timeout' => (int) config('logistics-planning.osrm_timeout', 5),
            'vroom_url' => (string) config('logistics-planning.routing.vroom_url', ''),
        ];
    }

    public function save(array $data): void
    {
        $this->publish(IntegrationKeys::ROUTING, [
            'enabled' => self::bool($data, 'enabled'),
            'osrm_host' => self::str($data, 'osrm_host'),
            'osrm_timeout' => self::int($data, 'osrm_timeout', 5),
            'vroom_url' => self::str($data, 'vroom_url'),
            'backend' => self::str($data, 'backend') ?: 'builtin',
        ]);
    }
}
