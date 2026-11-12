<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Configuration\Integrations;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Logistics\Settings\Infrastructure\Integration\IntegrationKeys;

/**
 * Almacenamiento S3-compatible (MinIO, R2, Spaces o AWS S3) de documentos y evidencias.
 */
final class StorageSection extends IntegrationSection
{
    public static function slug(): string
    {
        return 'integrations-storage';
    }

    public static function label(): string
    {
        return 'Storage';
    }

    public static function sort(): int
    {
        return 30;
    }

    public function components(): array
    {
        return [
            Section::make(__('General Configuration'))
                ->collapsible()
                ->schema([
                    self::enabledToggle('S3-compatible credentials for document and evidence disks. Applied to every S3 disk.'),
                ]),
            Section::make(__('Credentials'))
                ->collapsible()
                ->schema([
                    self::secretInput(IntegrationKeys::STORAGE, 'key', 'Access key', 'Stored encrypted. Leave blank to keep the current key.')
                        ->maxLength(255),
                    self::secretInput(IntegrationKeys::STORAGE, 'secret', 'Secret key', 'Stored encrypted. Leave blank to keep the current secret.')
                        ->maxLength(255),
                ]),
            Section::make(__('Bucket'))
                ->collapsible()
                ->schema([
                    TextInput::make('region')
                        ->label(self::scoped('Region'))
                        ->maxLength(255),
                    TextInput::make('bucket')
                        ->label(self::scoped('Default bucket'))
                        ->maxLength(255)
                        ->helperText(__('Used by disks that do not define their own bucket.')),
                    TextInput::make('endpoint')
                        ->label(self::scoped('Endpoint'))
                        ->url()
                        ->maxLength(255)
                        ->helperText(__('Custom endpoint for S3-compatible storage, e.g. https://minio.example.com.')),
                    TextInput::make('url')
                        ->label(self::scoped('Public URL'))
                        ->maxLength(255),
                    Toggle::make('use_path_style_endpoint')
                        ->label(self::scoped('Use path-style endpoint')),
                ]),
        ];
    }

    public function state(): array
    {
        return [
            'enabled' => self::config()->enabled(IntegrationKeys::STORAGE, false),
            'key' => '',
            'secret' => '',
            'region' => (string) config('filesystems.disks.s3.region', ''),
            'bucket' => (string) config('filesystems.disks.s3.bucket', ''),
            'endpoint' => (string) config('filesystems.disks.s3.endpoint', ''),
            'url' => (string) config('filesystems.disks.s3.url', ''),
            'use_path_style_endpoint' => (bool) config('filesystems.disks.s3.use_path_style_endpoint', false),
        ];
    }

    public function save(array $data): void
    {
        $value = [
            'enabled' => self::bool($data, 'enabled'),
            'region' => self::str($data, 'region'),
            'bucket' => self::str($data, 'bucket'),
            'endpoint' => self::str($data, 'endpoint'),
            'url' => self::str($data, 'url'),
            'use_path_style_endpoint' => self::bool($data, 'use_path_style_endpoint'),
        ];

        $value = $this->withSecret($value, IntegrationKeys::STORAGE, $data, 'key');
        $value = $this->withSecret($value, IntegrationKeys::STORAGE, $data, 'secret');

        $this->publish(IntegrationKeys::STORAGE, $value);
    }
}
