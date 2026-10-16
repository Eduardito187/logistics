<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Resources\IntegrationResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Crypt;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Infrastructure\Integration\IntegrationConfig;
use Logistics\Settings\Infrastructure\Integration\IntegrationKeys;
use Logistics\Settings\Presentation\Filament\Resources\IntegrationResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Pantalla de gestión del panel de Integraciones.
 *
 * Muestra una tarjeta por integración configurable (ruteo, SAP, almacenamiento) con su estado vigente y
 * un botón "Configure" que abre un formulario modal: switch on/off + credenciales/parámetros. Guardar
 * despacha SetSetting al bus CQRS (historial versionado, auditable); los secretos (contraseña SAP,
 * llave/secreto S3) se cifran con Crypt::encryptString y NO se muestran en claro (placeholder "already
 * set"). Debajo, el estado SOLO LECTURA del geocoder y de las notificaciones, que administra otra parte.
 *
 * No escribe Eloquent: todo cambio pasa por el command de settings. Texto visible en inglés (regla 5);
 * comentarios en español (regla 6). Los secretos nunca se cachean como objeto: viajan como string cifrado.
 */
final class ManageIntegrations extends Page
{
    protected static string $resource = IntegrationResource::class;

    protected string $view = 'logistics-settings::filament.pages.manage-integrations';

    public function getTitle(): string
    {
        return __('Integrations');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->configureRoutingAction(),
            $this->configureSapAction(),
            $this->configureStorageAction(),
            $this->configureFcmAction(),
        ];
    }

    // --- Ruteo (OSRM / VROOM) ------------------------------------------------------------------------

    private function configureRoutingAction(): Action
    {
        return Action::make('configure_routing')
            ->label(__('Configure routing'))
            ->icon('heroicon-o-map')
            ->modalHeading(__('Routing integration'))
            ->modalDescription(__('Road routing engines (OSRM and VROOM). Leave a field empty to fall back to the environment default.'))
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (): array => $this->routingFormState())
            ->schema([
                Toggle::make('enabled')
                    ->label(__('Enabled'))
                    ->helperText(__('When off, the platform uses the environment defaults.')),
                TextInput::make('osrm_host')
                    ->label(__('OSRM host'))
                    ->url()
                    ->maxLength(255)
                    ->helperText(__('Base URL of the OSRM routing server, e.g. http://osrm:5000.')),
                TextInput::make('osrm_timeout')
                    ->label(__('OSRM timeout (seconds)'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(120),
                TextInput::make('vroom_url')
                    ->label(__('VROOM URL'))
                    ->url()
                    ->maxLength(255)
                    ->helperText(__('Optional VROOM solver endpoint, e.g. http://vroom:3000. Used only with the VROOM backend.')),
                Select::make('backend')
                    ->label(__('Routing backend'))
                    ->native(false)
                    ->options([
                        'builtin' => __('Built-in (Clarke-Wright + 2-opt)'),
                        'osrm_trip' => __('OSRM trip'),
                        'vroom' => __('VROOM'),
                    ]),
            ])
            ->action(function (array $data): void {
                $value = [
                    'enabled' => $this->boolInput($data, 'enabled'),
                    'osrm_host' => $this->strInput($data, 'osrm_host'),
                    'osrm_timeout' => $this->intInput($data, 'osrm_timeout', 5),
                    'vroom_url' => $this->strInput($data, 'vroom_url'),
                    'backend' => $this->strInput($data, 'backend') ?: 'builtin',
                ];

                $this->publish(IntegrationKeys::ROUTING, $value, __('Routing integration saved'));
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function routingFormState(): array
    {
        $integrations = app(IntegrationConfig::class);

        return [
            'enabled' => $integrations->enabled(IntegrationKeys::ROUTING, true),
            'osrm_host' => (string) config('logistics-planning.osrm_host', ''),
            'osrm_timeout' => (int) config('logistics-planning.osrm_timeout', 5),
            'vroom_url' => (string) config('logistics-planning.routing.vroom_url', ''),
            'backend' => (string) config('logistics-planning.routing.backend', 'builtin'),
        ];
    }

    // --- Conector SAP --------------------------------------------------------------------------------

    private function configureSapAction(): Action
    {
        return Action::make('configure_sap')
            ->label(__('Configure SAP'))
            ->icon('heroicon-o-circle-stack')
            ->color('gray')
            ->modalHeading(__('SAP connector integration'))
            ->modalDescription(__('Endpoint and service credentials for the SAP connector. Without a base URL the connector falls back to the null stub.'))
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (): array => $this->sapFormState())
            ->schema([
                Toggle::make('enabled')
                    ->label(__('Enabled')),
                TextInput::make('base_url')
                    ->label(__('Base URL'))
                    ->url()
                    ->maxLength(255)
                    ->helperText(__('SAP OData/REST base URL, e.g. https://sap.example.com/odata.')),
                TextInput::make('username')
                    ->label(__('Username'))
                    ->maxLength(255),
                TextInput::make('password')
                    ->label(__('Password'))
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    ->placeholder($this->secretPlaceholder(IntegrationKeys::SAP, 'password'))
                    ->helperText(__('Stored encrypted. Leave blank to keep the current password.')),
                TextInput::make('orders_path')
                    ->label(__('Orders path'))
                    ->maxLength(255),
                TextInput::make('stock_transfers_path')
                    ->label(__('Stock transfers path'))
                    ->maxLength(255),
                TextInput::make('timeout')
                    ->label(__('Timeout (seconds)'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(120),
            ])
            ->action(function (array $data): void {
                $integrations = app(IntegrationConfig::class);
                $existing = $integrations->config(IntegrationKeys::SAP);

                $value = [
                    'enabled' => $this->boolInput($data, 'enabled'),
                    'base_url' => $this->strInput($data, 'base_url'),
                    'username' => $this->strInput($data, 'username'),
                    'orders_path' => $this->strInput($data, 'orders_path') ?: '/orders/pending',
                    'stock_transfers_path' => $this->strInput($data, 'stock_transfers_path') ?: '/stock-transfers',
                    'timeout' => $this->intInput($data, 'timeout', 10),
                ];

                $value = $this->mergeSecret($value, $existing, $data, 'password');

                $this->publish(IntegrationKeys::SAP, $value, __('SAP connector integration saved'));
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function sapFormState(): array
    {
        $integrations = app(IntegrationConfig::class);

        return [
            'enabled' => $integrations->enabled(IntegrationKeys::SAP, false),
            'base_url' => (string) config('logistics-sap-connector.sap.base_url', ''),
            'username' => (string) config('logistics-sap-connector.sap.username', ''),
            // El secreto nunca se precarga: en blanco se conserva el guardado.
            'password' => '',
            'orders_path' => (string) config('logistics-sap-connector.sap.orders_path', '/orders/pending'),
            'stock_transfers_path' => (string) config('logistics-sap-connector.sap.stock_transfers_path', '/stock-transfers'),
            'timeout' => (int) config('logistics-sap-connector.sap.timeout', 10),
        ];
    }

    // --- Almacenamiento (S3) -------------------------------------------------------------------------

    private function configureStorageAction(): Action
    {
        return Action::make('configure_storage')
            ->label(__('Configure storage'))
            ->icon('heroicon-o-cloud')
            ->color('gray')
            ->modalHeading(__('Storage integration'))
            ->modalDescription(__('S3-compatible credentials for document and evidence disks (MinIO, R2, Spaces or AWS S3). Applied to every S3 disk.'))
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (): array => $this->storageFormState())
            ->schema([
                Toggle::make('enabled')
                    ->label(__('Enabled')),
                TextInput::make('key')
                    ->label(__('Access key'))
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    ->placeholder($this->secretPlaceholder(IntegrationKeys::STORAGE, 'key'))
                    ->helperText(__('Stored encrypted. Leave blank to keep the current key.')),
                TextInput::make('secret')
                    ->label(__('Secret key'))
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    ->placeholder($this->secretPlaceholder(IntegrationKeys::STORAGE, 'secret'))
                    ->helperText(__('Stored encrypted. Leave blank to keep the current secret.')),
                TextInput::make('region')
                    ->label(__('Region'))
                    ->maxLength(255),
                TextInput::make('bucket')
                    ->label(__('Default bucket'))
                    ->maxLength(255)
                    ->helperText(__('Used by disks that do not define their own bucket.')),
                TextInput::make('endpoint')
                    ->label(__('Endpoint'))
                    ->url()
                    ->maxLength(255)
                    ->helperText(__('Custom endpoint for S3-compatible storage, e.g. http://minio:9000.')),
                TextInput::make('url')
                    ->label(__('Public URL'))
                    ->maxLength(255),
                Toggle::make('use_path_style_endpoint')
                    ->label(__('Use path-style endpoint')),
            ])
            ->action(function (array $data): void {
                $integrations = app(IntegrationConfig::class);
                $existing = $integrations->config(IntegrationKeys::STORAGE);

                $value = [
                    'enabled' => $this->boolInput($data, 'enabled'),
                    'region' => $this->strInput($data, 'region'),
                    'bucket' => $this->strInput($data, 'bucket'),
                    'endpoint' => $this->strInput($data, 'endpoint'),
                    'url' => $this->strInput($data, 'url'),
                    'use_path_style_endpoint' => $this->boolInput($data, 'use_path_style_endpoint'),
                ];

                $value = $this->mergeSecret($value, $existing, $data, 'key');
                $value = $this->mergeSecret($value, $existing, $data, 'secret');

                $this->publish(IntegrationKeys::STORAGE, $value, __('Storage integration saved'));
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function storageFormState(): array
    {
        $integrations = app(IntegrationConfig::class);

        return [
            'enabled' => $integrations->enabled(IntegrationKeys::STORAGE, false),
            'key' => '',
            'secret' => '',
            'region' => (string) config('filesystems.disks.s3.region', ''),
            'bucket' => (string) config('filesystems.disks.s3.bucket', ''),
            'endpoint' => (string) config('filesystems.disks.s3.endpoint', ''),
            'url' => (string) config('filesystems.disks.s3.url', ''),
            'use_path_style_endpoint' => (bool) config('filesystems.disks.s3.use_path_style_endpoint', false),
        ];
    }

    // --- Push Firebase Cloud Messaging (FCM) ---------------------------------------------------------

    private function configureFcmAction(): Action
    {
        return Action::make('configure_fcm')
            ->label(__('Configure push (FCM)'))
            ->icon('heroicon-o-bell-alert')
            ->color('gray')
            ->modalHeading(__('Firebase Cloud Messaging integration'))
            ->modalDescription(__('Credentials for push notifications to the customer app via Firebase Cloud Messaging.'))
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (): array => $this->fcmFormState())
            ->schema([
                Toggle::make('enabled')
                    ->label(__('Enabled')),
                TextInput::make('project_id')
                    ->label(__('Project ID'))
                    ->maxLength(255)
                    ->helperText(__('Firebase project ID that owns the service account.')),
                TextInput::make('credentials_path')
                    ->label(__('Service account path'))
                    ->maxLength(255)
                    ->helperText(__('Path to the service-account.json, e.g. storage/app/firebase/service-account.json. Used when no inline JSON is stored.')),
                TextInput::make('credentials_json')
                    ->label(__('Service account JSON'))
                    ->password()
                    ->revealable()
                    ->placeholder($this->secretPlaceholder(IntegrationKeys::FCM, 'credentials_json'))
                    ->helperText(__('Stored encrypted. Paste the service account JSON to store it inline, or leave blank to keep the current value and use the path above.')),
                TextInput::make('timeout')
                    ->label(__('Timeout (seconds)'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(120),
            ])
            ->action(function (array $data): void {
                $integrations = app(IntegrationConfig::class);
                $existing = $integrations->config(IntegrationKeys::FCM);

                $value = [
                    'enabled' => $this->boolInput($data, 'enabled'),
                    'project_id' => $this->strInput($data, 'project_id'),
                    'credentials_path' => $this->strInput($data, 'credentials_path'),
                    'timeout' => $this->intInput($data, 'timeout', 10),
                ];

                $value = $this->mergeSecret($value, $existing, $data, 'credentials_json');

                $this->publish(IntegrationKeys::FCM, $value, __('Firebase Cloud Messaging integration saved'));
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function fcmFormState(): array
    {
        $integrations = app(IntegrationConfig::class);

        return [
            'enabled' => $integrations->enabled(IntegrationKeys::FCM, false),
            'project_id' => (string) config('logistics-notifications.fcm.project_id', ''),
            'credentials_path' => (string) config('logistics-notifications.fcm.credentials', ''),
            // El secreto nunca se precarga: en blanco se conserva el guardado.
            'credentials_json' => '',
            'timeout' => (int) config('logistics-notifications.fcm.timeout', 10),
        ];
    }

    // --- Datos para la vista (estado de cada integración + solo lectura) -----------------------------

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $integrations = app(IntegrationConfig::class);

        return [
            'routing' => [
                'configured' => $integrations->config(IntegrationKeys::ROUTING) !== [],
                'enabled' => $integrations->enabled(IntegrationKeys::ROUTING, true),
                'osrm_host' => (string) config('logistics-planning.osrm_host', ''),
                'vroom_url' => (string) config('logistics-planning.routing.vroom_url', ''),
                'backend' => (string) config('logistics-planning.routing.backend', 'builtin'),
            ],
            'sap' => [
                'configured' => $integrations->config(IntegrationKeys::SAP) !== [],
                'enabled' => $integrations->enabled(IntegrationKeys::SAP, false),
                'base_url' => (string) config('logistics-sap-connector.sap.base_url', ''),
                'username' => (string) config('logistics-sap-connector.sap.username', ''),
                'has_password' => $integrations->hasSecret(IntegrationKeys::SAP, 'password'),
            ],
            'storage' => [
                'configured' => $integrations->config(IntegrationKeys::STORAGE) !== [],
                'enabled' => $integrations->enabled(IntegrationKeys::STORAGE, false),
                'region' => (string) config('filesystems.disks.s3.region', ''),
                'bucket' => (string) config('filesystems.disks.s3.bucket', ''),
                'endpoint' => (string) config('filesystems.disks.s3.endpoint', ''),
                'has_key' => $integrations->hasSecret(IntegrationKeys::STORAGE, 'key'),
                'has_secret' => $integrations->hasSecret(IntegrationKeys::STORAGE, 'secret'),
            ],
            // Estado SOLO LECTURA de integraciones administradas por otra parte (geocoder y notificaciones):
            // se leen de su config; aquí no se editan.
            'geocoder' => [
                'driver' => (string) config('logistics-shipments.geocoder.driver', ''),
                'nominatim_enabled' => (bool) config('logistics-shipments.geocoder.nominatim.enabled', false),
                'nominatim_endpoint' => (string) config('logistics-shipments.geocoder.nominatim.endpoint', ''),
                'tile_url' => (string) config('maps.tile_url', ''),
            ],
            // Push por Firebase Cloud Messaging: integración configurable desde este panel.
            'fcm' => [
                'configured' => $integrations->config(IntegrationKeys::FCM) !== [],
                'enabled' => $integrations->enabled(IntegrationKeys::FCM, false),
                'project_id' => (string) config('logistics-notifications.fcm.project_id', ''),
                'credentials_path' => (string) config('logistics-notifications.fcm.credentials', ''),
                'has_credentials' => $integrations->hasSecret(IntegrationKeys::FCM, 'credentials_json'),
            ],
            'notifications' => [
                'telegram' => (string) config('logistics-notifications.telegram.bot_token', '') !== '',
                'sms' => (string) config('logistics-notifications.sms.url', '') !== '',
                'email_mailer' => (string) config('logistics-notifications.email.mailer', ''),
            ],
        ];
    }

    // --- Helpers -------------------------------------------------------------------------------------

    /**
     * Publica una nueva versión de la clave de integración despachando el command de settings y avisa.
     *
     * @param array<string, mixed> $value
     */
    private function publish(string $key, array $value, string $successTitle): void
    {
        app(CommandBus::class)->dispatch(new SetSettingCommand(
            key: $key,
            value: $value,
            effectiveFrom: null,
            createdBy: $this->currentUserId(),
        ));

        Notification::make()
            ->title($successTitle)
            ->body(__('The change takes effect on the next request.'))
            ->success()
            ->send();
    }

    /**
     * Resuelve el valor cifrado de un secreto: si el formulario trae uno nuevo lo cifra; si viene en
     * blanco, conserva el que ya estaba guardado (cifrado). Si no hay ni nuevo ni previo, no setea la clave.
     *
     * @param  array<string, mixed> $value    valor que se está armando para guardar
     * @param  array<string, mixed> $existing valor previo guardado (secretos ya cifrados)
     * @param  array<string, mixed> $data     entrada del formulario
     * @return array<string, mixed>
     */
    private function mergeSecret(array $value, array $existing, array $data, string $param): array
    {
        $input = $this->strInput($data, $param);
        if ($input !== '') {
            $value[$param] = Crypt::encryptString($input);

            return $value;
        }

        /** @var mixed $previous */
        $previous = $existing[$param] ?? null;
        if (is_string($previous) && $previous !== '') {
            $value[$param] = $previous;
        }

        return $value;
    }

    /**
     * Placeholder del campo de un secreto: indica si ya hay uno guardado sin revelarlo.
     */
    private function secretPlaceholder(string $key, string $param): string
    {
        return app(IntegrationConfig::class)->hasSecret($key, $param)
            ? __('•••• already set')
            : __('Not set');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function strInput(array $data, string $param): string
    {
        /** @var mixed $value */
        $value = $data[$param] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function intInput(array $data, string $param, int $default): int
    {
        /** @var mixed $value */
        $value = $data[$param] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function boolInput(array $data, string $param): bool
    {
        return (bool) ($data[$param] ?? false);
    }

    private function currentUserId(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }
}
