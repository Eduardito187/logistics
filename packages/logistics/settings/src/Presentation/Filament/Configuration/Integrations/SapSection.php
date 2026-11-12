<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Configuration\Integrations;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Logistics\Settings\Infrastructure\Integration\IntegrationKeys;

/**
 * Conector SAP (ADR 002): interruptor real, endpoint, credenciales del servicio, rutas y avisos
 * fire-and-forget de hechos de despacho (ruta de eventos, eventos a avisar, intentos y timeout). Sin URL
 * base el conector usa el stub; apagado, no sale nada hacia SAP y los avisos quedan «skipped».
 */
final class SapSection extends IntegrationSection
{
    public static function slug(): string
    {
        return 'integrations-sap';
    }

    public static function label(): string
    {
        return 'SAP connector';
    }

    public static function sort(): int
    {
        return 20;
    }

    public function components(): array
    {
        return [
            Section::make(__('General Configuration'))
                ->collapsible()
                ->schema([
                    self::enabledToggle('Real on/off switch. When disabled, nothing is sent to SAP and event notifications are recorded as skipped. Without a base URL the connector falls back to the null stub.'),
                ]),
            Section::make(__('Connection'))
                ->collapsible()
                ->schema([
                    TextInput::make('base_url')
                        ->label(self::scoped('Base URL'))
                        ->url()
                        ->maxLength(255)
                        ->helperText(__('SAP OData/REST base URL, e.g. https://sap.example.com/odata.')),
                    TextInput::make('username')
                        ->label(self::scoped('Username'))
                        ->maxLength(255),
                    self::secretInput(IntegrationKeys::SAP, 'password', 'Password', 'Stored encrypted. Leave blank to keep the current password.')
                        ->maxLength(255),
                    self::timeoutInput(),
                ]),
            Section::make(__('Endpoints'))
                ->collapsible()
                ->schema([
                    TextInput::make('orders_path')
                        ->label(self::scoped('Orders path'))
                        ->maxLength(255),
                    TextInput::make('stock_transfers_path')
                        ->label(self::scoped('Stock transfers path'))
                        ->maxLength(255),
                    TextInput::make('events_path')
                        ->label(self::scoped('Events path'))
                        ->maxLength(255)
                        ->helperText(__('Endpoint that receives dispatch event notifications (POST), e.g. /events.')),
                ]),
            Section::make(__('Event notifications'))
                ->description(__('Fire-and-forget: each selected event is recorded and sent to SAP in the background. SAP errors, slowness or downtime never affect dispatch operations.'))
                ->collapsible()
                ->schema([
                    Select::make('notify_events')
                        ->label(self::scoped('Events to notify'))
                        ->multiple()
                        ->native(false)
                        ->searchable()
                        ->options(self::eventOptions())
                        ->helperText(__('Leave empty to stop notifying SAP. Events are recorded as skipped while the connector is disabled or has no base URL.')),
                    TextInput::make('max_attempts')
                        ->label(self::scoped('Max attempts'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(10)
                        ->helperText(__('Total send attempts per notification. After that it stays failed and no action is required.')),
                    TextInput::make('notification_timeout')
                        ->label(self::scoped('Notification timeout (seconds)'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(30),
                ]),
        ];
    }

    /**
     * Opciones de «Events to notify»: el catálogo de eventos avisables que publica el conector SAP en su
     * config (event_name => etiqueta en inglés), traducido. Se lee de config para no acoplar este package
     * a las clases del conector.
     *
     * @return array<string, string>
     */
    private static function eventOptions(): array
    {
        $options = [];
        foreach ((array) config('logistics-sap-connector.notifications.catalog', []) as $event => $label) {
            if (is_string($event) && is_string($label)) {
                $options[$event] = __($label);
            }
        }

        return $options;
    }

    /**
     * Lista de eventos elegidos, solo strings no vacíos del catálogo conocido.
     *
     * @param  array<string, mixed> $data
     * @return list<string>
     */
    private static function selectedEvents(array $data): array
    {
        $known = array_keys(self::eventOptions());
        $selected = [];
        foreach ((array) ($data['notify_events'] ?? []) as $event) {
            if (is_string($event) && in_array($event, $known, true) && !in_array($event, $selected, true)) {
                $selected[] = $event;
            }
        }

        return $selected;
    }

    public function state(): array
    {
        return [
            // Sin nada guardado, el toggle refleja el interruptor efectivo por env (`sap.enabled`).
            'enabled' => self::config()->enabled(IntegrationKeys::SAP, (bool) config('logistics-sap-connector.sap.enabled', true)),
            'base_url' => (string) config('logistics-sap-connector.sap.base_url', ''),
            'username' => (string) config('logistics-sap-connector.sap.username', ''),
            'password' => '',
            'timeout' => (int) config('logistics-sap-connector.sap.timeout', 10),
            'orders_path' => (string) config('logistics-sap-connector.sap.orders_path', '/orders/pending'),
            'stock_transfers_path' => (string) config('logistics-sap-connector.sap.stock_transfers_path', '/stock-transfers'),
            'events_path' => (string) config('logistics-sap-connector.sap.events_path', '/events'),
            'notify_events' => array_values(array_filter(
                (array) config('logistics-sap-connector.notifications.events', []),
                static fn (mixed $event): bool => is_string($event) && $event !== '',
            )),
            'max_attempts' => (int) config('logistics-sap-connector.notifications.max_attempts', 3),
            'notification_timeout' => (int) config('logistics-sap-connector.notifications.timeout', 5),
        ];
    }

    public function save(array $data): void
    {
        $value = [
            'enabled' => self::bool($data, 'enabled'),
            'base_url' => self::str($data, 'base_url'),
            'username' => self::str($data, 'username'),
            'orders_path' => self::str($data, 'orders_path') ?: '/orders/pending',
            'stock_transfers_path' => self::str($data, 'stock_transfers_path') ?: '/stock-transfers',
            'timeout' => self::int($data, 'timeout', 10),
            'events_path' => self::str($data, 'events_path') ?: '/events',
            'notify_events' => self::selectedEvents($data),
            'max_attempts' => max(1, min(10, self::int($data, 'max_attempts', 3))),
            'notification_timeout' => max(1, min(30, self::int($data, 'notification_timeout', 5))),
        ];

        $this->publish(IntegrationKeys::SAP, $this->withSecret($value, IntegrationKeys::SAP, $data, 'password'));
    }
}
