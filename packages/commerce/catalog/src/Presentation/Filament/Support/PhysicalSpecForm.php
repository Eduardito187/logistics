<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Support;

use Commerce\Catalog\Application\Command\SetCategoryPhysicalDefaults\SetCategoryPhysicalDefaults;
use Commerce\Catalog\Application\Command\SetProductPhysicalSpec\SetProductPhysicalSpec;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryRecord;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\ProductRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Piezas de admin para las medidas físicas (Roadmap 02, Fase 2.0): campos (peso kg, L/A/H cm, volumen
 * calculado de solo lectura), acción "Physical specs" del producto y "Logistics defaults" de la
 * categoría. Toda mutación va por el bus CQRS (SetProductPhysicalSpec / SetCategoryPhysicalDefaults);
 * nunca se escribe el record. Texto visible en inglés.
 *
 * Las medidas son opcionales: dejarlas vacías nunca bloquea nada (aguas abajo se cae a la categoría o al
 * default global y se marca la fuente).
 */
final class PhysicalSpecForm
{
    /** Campos del formulario de medidas. */
    private const FIELDS = ['weight_kg', 'length_cm', 'width_cm', 'height_cm'];

    /**
     * Campos editables + volumen calculado (solo lectura, se recalcula al salir de cada dimensión).
     *
     * @return list<TextInput|TextEntry>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('weight_kg')
                ->label(__('Weight (kg)'))
                ->numeric()
                ->step('any')
                ->rule('gt:0')
                ->maxValue(20000)
                ->suffix('kg'),
            self::dimension('length_cm', __('Length (cm)')),
            self::dimension('width_cm', __('Width (cm)')),
            self::dimension('height_cm', __('Height (cm)')),
            TextEntry::make('volume_preview')
                ->label(__('Volume (m³)'))
                ->state(static fn (Get $get): string => self::formatVolume(self::volumeOf(
                    $get('length_cm'),
                    $get('width_cm'),
                    $get('height_cm'),
                )))
                ->helperText(__('Calculated from length × width × height. Requires all three dimensions.')),
        ];
    }

    /**
     * Acción "Physical specs" sobre un producto (fila de la tabla o cabecera del hub).
     */
    public static function productAction(): Action
    {
        return Action::make('physicalSpecs')
            ->label(__('Physical specs'))
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->modalHeading(__('Physical specs'))
            ->modalDescription(__('Weight and dimensions per unit of product. Missing values never block shipping: a default is used and flagged.'))
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(static fn (Model $record): array => self::fillFrom($record))
            ->schema(self::fields())
            ->action(static function (Model $record, array $data): void {
                if (!$record instanceof ProductRecord) {
                    return;
                }

                self::dispatchSafely(new SetProductPhysicalSpec(
                    productId: (string) $record->getKey(),
                    sku: null,
                    weightKg: self::toFloat($data['weight_kg'] ?? null),
                    lengthCm: self::toFloat($data['length_cm'] ?? null),
                    widthCm: self::toFloat($data['width_cm'] ?? null),
                    heightCm: self::toFloat($data['height_cm'] ?? null),
                ), __('Physical specs saved'));
            });
    }

    /**
     * Acción "Logistics defaults" sobre una categoría: medidas por defecto de sus productos.
     */
    public static function categoryAction(): Action
    {
        return Action::make('logisticsDefaults')
            ->label(__('Logistics defaults'))
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->modalHeading(__('Logistics defaults'))
            ->modalDescription(__('Default weight and dimensions for products of this category that have no measurements of their own.'))
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(static fn (Model $record): array => self::fillFrom($record))
            ->schema(self::fields())
            ->action(static function (Model $record, array $data): void {
                if (!$record instanceof CategoryRecord) {
                    return;
                }

                self::dispatchSafely(new SetCategoryPhysicalDefaults(
                    categoryId: (string) $record->getKey(),
                    weightKg: self::toFloat($data['weight_kg'] ?? null),
                    lengthCm: self::toFloat($data['length_cm'] ?? null),
                    widthCm: self::toFloat($data['width_cm'] ?? null),
                    heightCm: self::toFloat($data['height_cm'] ?? null),
                ), __('Logistics defaults saved'));
            });
    }

    /**
     * Estado de completitud de las medidas de un record (para badges): complete | partial | missing.
     */
    public static function completeness(Model $record): string
    {
        $present = 0;
        foreach (self::FIELDS as $field) {
            if (self::toFloat($record->getAttribute($field)) !== null) {
                $present++;
            }
        }

        return match (true) {
            $present === count(self::FIELDS) => 'complete',
            $present === 0 => 'missing',
            default => 'partial',
        };
    }

    /**
     * Etiqueta legible del estado de completitud.
     */
    public static function completenessLabel(string $state): string
    {
        return match ($state) {
            'complete' => __('Complete'),
            'partial' => __('Partial'),
            default => __('Missing'),
        };
    }

    public static function completenessColor(string $state): string
    {
        return match ($state) {
            'complete' => 'success',
            'partial' => 'warning',
            default => 'gray',
        };
    }

    /**
     * Volumen de un record (L×A×H/1e6) formateado, o "—" si falta alguna dimensión.
     */
    public static function recordVolume(Model $record): string
    {
        return self::formatVolume(self::volumeOf(
            $record->getAttribute('length_cm'),
            $record->getAttribute('width_cm'),
            $record->getAttribute('height_cm'),
        ));
    }

    /**
     * Volumen de VISTA PREVIA en m³. La fuente de verdad es PhysicalSpec::volumeM3() del dominio; esto
     * es solo aritmética de presentación para mostrarlo en vivo mientras se edita.
     */
    private static function volumeOf(mixed $length, mixed $width, mixed $height): ?float
    {
        $l = self::toFloat($length);
        $w = self::toFloat($width);
        $h = self::toFloat($height);

        if ($l === null || $w === null || $h === null || $l <= 0.0 || $w <= 0.0 || $h <= 0.0) {
            return null;
        }

        return round($l * $w * $h / 1_000_000, 6);
    }

    private static function formatVolume(?float $volume): string
    {
        return $volume === null ? '—' : rtrim(rtrim(number_format($volume, 6, '.', ''), '0'), '.').' m³';
    }

    private static function dimension(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->step('any')
            ->rule('gt:0')
            ->maxValue(2000)
            ->suffix('cm')
            ->live(onBlur: true);
    }

    /**
     * @return array<string, string|null>
     */
    private static function fillFrom(Model $record): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = self::toFloat($record->getAttribute($field));
            $values[$field] = $value !== null ? (string) $value : null;
        }

        return $values;
    }

    private static function dispatchSafely(Command $command, string $successTitle): void
    {
        try {
            app(CommandBus::class)->dispatch($command);
        } catch (DomainException) {
            // El mensaje del dominio está en español: en el admin se muestra un texto propio en inglés.
            Notification::make()
                ->title(__('Invalid measurements'))
                ->body(__('Every value must be greater than 0 and within the allowed range.'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($successTitle)
            ->success()
            ->send();
    }

    private static function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
