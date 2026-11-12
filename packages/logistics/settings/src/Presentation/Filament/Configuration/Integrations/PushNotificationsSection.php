<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Configuration\Integrations;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Logistics\Settings\Infrastructure\Integration\IntegrationKeys;

/**
 * Push de la app de clientes por Firebase Cloud Messaging.
 */
final class PushNotificationsSection extends IntegrationSection
{
    public static function slug(): string
    {
        return 'integrations-push';
    }

    public static function label(): string
    {
        return 'Push notifications';
    }

    public static function sort(): int
    {
        return 40;
    }

    public function components(): array
    {
        return [
            Section::make(__('General Configuration'))
                ->collapsible()
                ->schema([
                    self::enabledToggle('Push notifications to the customer app via Firebase Cloud Messaging.'),
                    TextInput::make('project_id')
                        ->label(self::scoped('Project ID'))
                        ->maxLength(255)
                        ->helperText(__('Firebase project ID that owns the service account.')),
                    self::timeoutInput(),
                ]),
            Section::make(__('Service account'))
                ->collapsible()
                ->schema([
                    TextInput::make('credentials_path')
                        ->label(self::scoped('Service account path'))
                        ->maxLength(255)
                        ->helperText(__('Path to the service-account.json, e.g. storage/app/firebase/service-account.json. Used when no inline JSON is stored.')),
                    self::secretInput(
                        IntegrationKeys::FCM,
                        'credentials_json',
                        'Service account JSON',
                        'Stored encrypted. Paste the service account JSON to store it inline, or leave blank to keep the current value and use the path above.',
                    ),
                ]),
        ];
    }

    public function state(): array
    {
        return [
            'enabled' => self::config()->enabled(IntegrationKeys::FCM, false),
            'project_id' => (string) config('logistics-notifications.fcm.project_id', ''),
            'timeout' => (int) config('logistics-notifications.fcm.timeout', 10),
            'credentials_path' => (string) config('logistics-notifications.fcm.credentials', ''),
            'credentials_json' => '',
        ];
    }

    public function save(array $data): void
    {
        $value = [
            'enabled' => self::bool($data, 'enabled'),
            'project_id' => self::str($data, 'project_id'),
            'credentials_path' => self::str($data, 'credentials_path'),
            'timeout' => self::int($data, 'timeout', 10),
        ];

        $this->publish(IntegrationKeys::FCM, $this->withSecret($value, IntegrationKeys::FCM, $data, 'credentials_json'));
    }
}
