<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\BulkAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Stringable as NativeStringable;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Acción masiva universal y SOLO LECTURA: exporta las filas seleccionadas a un CSV usando exactamente
 * las columnas visibles de la tabla (mismas etiquetas y mismo formato que ve el usuario). Al reusar las
 * columnas de la tabla, nunca exporta el id (UUID): esas columnas no lo muestran (regla "sin UUID").
 *
 * No muta nada: es segura en cualquier listado. Tolerante por celda: si una columna falla al formatear,
 * esa celda va vacía en vez de romper toda la exportación.
 */
final class TableCsvExport
{
    /**
     * Construye la bulk action "Export CSV" lista para meter dentro de un BulkActionGroup.
     */
    public static function bulkAction(): BulkAction
    {
        return BulkAction::make('exportCsv')
            ->label(__('Export CSV'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->deselectRecordsAfterCompletion()
            ->accessSelectedRecords()
            ->action(static function (ListRecords $livewire, Collection $records): StreamedResponse {
                /** @var list<Column> $columns */
                $columns = array_values($livewire->getTable()->getColumns());

                $filename = 'export-'.now()->format('Ymd-His').'.csv';

                return response()->streamDownload(static function () use ($columns, $records): void {
                    $handle = fopen('php://output', 'wb');
                    if ($handle === false) {
                        return;
                    }

                    // Encabezados: la etiqueta visible de cada columna.
                    fputcsv($handle, array_map(
                        static fn (Column $column): string => self::stringify($column->getLabel()),
                        $columns,
                    ));

                    // Una fila por registro seleccionado, formateada como en la tabla.
                    foreach ($records as $record) {
                        if (!$record instanceof Model) {
                            continue;
                        }

                        $row = [];
                        foreach ($columns as $column) {
                            $row[] = self::cell($column, $record);
                        }

                        fputcsv($handle, $row);
                    }

                    fclose($handle);
                }, $filename, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            });
    }

    /**
     * Valor de una celda para el CSV: estado de la columna ya formateado (igual que en la tabla),
     * tolerante a fallos (celda vacía si algo revienta).
     */
    private static function cell(Column $column, Model $record): string
    {
        try {
            $column = (clone $column)->record($record);
            $state = $column->getState();

            // Solo las columnas con formato (p. ej. TextColumn) aplican formatStateUsing/badge/fecha;
            // para el resto va el estado crudo. Así el CSV refleja lo que ve el usuario en la tabla.
            $formatted = method_exists($column, 'formatState')
                ? $column->formatState($state)
                : $state;

            return self::stringify($formatted);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Convierte a texto plano cualquier valor de estado/etiqueta (string, Stringable, array, escalar),
     * limpiando HTML para que el CSV quede legible.
     */
    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return implode(', ', array_map(static fn (mixed $item): string => self::stringify($item), $value));
        }

        if ($value instanceof Stringable || $value instanceof NativeStringable) {
            $value = (string) $value;
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (!is_scalar($value)) {
            return '';
        }

        return trim(html_entity_decode(strip_tags((string) $value)));
    }
}
