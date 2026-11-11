<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Configuration\Integrations;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Logistics\Settings\Infrastructure\Integration\IntegrationKeys;

/**
 * Conector SAP (ADR 002): endpoint, credenciales del servicio y rutas. Sin URL base el conector usa el stub.
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
                    self::enabledToggle('Without a base URL the connector falls back to the null stub.'),
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
                ]),
        ];
    }

    public function state(): array
    {
        return [
            'enabled' => self::config()->enabled(IntegrationKeys::SAP, false),
            'base_url' => (string) config('logistics-sap-connector.sap.base_url', ''),
            'username' => (string) config('logistics-sap-connector.sap.username', ''),
            'password' => '',
            'timeout' => (int) config('logistics-sap-connector.sap.timeout', 10),
            'orders_path' => (string) config('logistics-sap-connector.sap.orders_path', '/orders/pending'),
            'stock_transfers_path' => (string) config('logistics-sap-connector.sap.stock_transfers_path', '/stock-transfers'),
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
        ];

        $this->publish(IntegrationKeys::SAP, $this->withSecret($value, IntegrationKeys::SAP, $data, 'password'));
    }
}
