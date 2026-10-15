<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use DateTimeImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentType;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Torre de control — licencias y documentos de flota por vencer o vencidos (choferes, ayudantes y
 * vehículos: SOAT, inspección técnica, licencia, etc.).
 *
 * Lee las cuatro fuentes de vencimiento del contexto flota por el QueryBus (ListExpiringDocuments con
 * ventana de 30 días) y clasifica cada documento por umbral: VENCIDO, ≤7 días, ≤30 días. Es el mismo
 * criterio que la alerta programada (AlertExpiringDocuments), así tablero y aviso coinciden. Las filas
 * se ordenan de lo más urgente a lo menos y enlazan al recurso del dueño. Texto en inglés (regla 5).
 */
final class LicensesDocumentsExpiringWidget extends TableWidget
{
    protected static ?int $sort = 3;

    /** Ventana de alerta del widget (días): incluye vencidos y hasta 30 días por vencer. */
    private const WINDOW_DAYS = 30;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Licenses & documents expiring'))
            ->description(__('Driver licenses and fleet documents that are expired or due within 30 days.'))
            ->records(fn (): array => $this->rows())
            ->paginated([10, 25, 50])
            ->emptyStateHeading(__('Nothing expiring'))
            ->emptyStateDescription(__('No licenses or documents are expired or due within 30 days.'))
            ->emptyStateIcon('heroicon-o-check-badge')
            ->columns([
                TextColumn::make('threshold_label')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (array $record): string => match ($record['threshold']) {
                        ExpiringDocumentView::THRESHOLD_EXPIRED => 'danger',
                        ExpiringDocumentView::THRESHOLD_DUE_SOON => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('owner_type_label')
                    ->label(__('Type'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('owner_label')
                    ->label(__('Driver / vehicle'))
                    ->weight('medium')
                    // Enlace al recurso del dueño (chofer, ayudante o vehículo); sin url si no se resuelve.
                    ->url(fn (array $record): ?string => $record['url'])
                    ->color(fn (array $record): string => $record['url'] !== null ? 'primary' : 'gray'),
                TextColumn::make('document_type_label')
                    ->label(__('Document')),
                TextColumn::make('expires_on')
                    ->label(__('Expires on'))
                    ->date('Y-m-d'),
            ]);
    }

    /**
     * Construye las filas del widget: lee los vencimientos por el QueryBus, los clasifica por umbral y
     * los ordena de lo más urgente (vencido) a lo menos. Tolera cualquier fallo de lectura devolviendo
     * una tabla vacía, para que el tablero nunca se caiga por el widget.
     *
     * @return list<array{threshold: string, threshold_label: string, owner_type: string, owner_type_label: string, owner_label: string, document_type_label: string, expires_on: string, url: string|null}>
     */
    private function rows(): array
    {
        try {
            /** @var list<ExpiringDocumentView> $views */
            $views = app(QueryBus::class)->ask(new ListExpiringDocuments(self::WINDOW_DAYS));
        } catch (Throwable) {
            return [];
        }

        $ref = new DateTimeImmutable('today');

        $rows = [];
        foreach ($views as $view) {
            $threshold = $view->thresholdAt($ref);
            $rows[] = [
                'threshold' => $threshold,
                'threshold_label' => $this->thresholdLabel($threshold),
                'owner_type' => $view->ownerType,
                'owner_type_label' => $this->ownerTypeLabel($view->ownerType),
                'owner_label' => $view->ownerLabel,
                'document_type_label' => $this->documentTypeLabel($view->documentType),
                'expires_on' => $view->expiresOn,
                'url' => $this->ownerUrl($view->ownerType, $view->ownerId),
            ];
        }

        // Lo más urgente primero (vencido → ≤7 → ≤30) y, dentro de cada grupo, por fecha de vencimiento.
        usort($rows, function (array $a, array $b): int {
            $order = [
                ExpiringDocumentView::THRESHOLD_EXPIRED => 0,
                ExpiringDocumentView::THRESHOLD_DUE_SOON => 1,
                ExpiringDocumentView::THRESHOLD_UPCOMING => 2,
            ];

            return [$order[$a['threshold']] ?? 3, $a['expires_on']]
                <=> [$order[$b['threshold']] ?? 3, $b['expires_on']];
        });

        return $rows;
    }

    private function thresholdLabel(string $threshold): string
    {
        return match ($threshold) {
            ExpiringDocumentView::THRESHOLD_EXPIRED => (string) __('Expired'),
            ExpiringDocumentView::THRESHOLD_DUE_SOON => (string) __('Due within 7 days'),
            default => (string) __('Due within 30 days'),
        };
    }

    private function ownerTypeLabel(string $ownerType): string
    {
        return match ($ownerType) {
            'driver' => (string) __('Driver'),
            'helper' => (string) __('Helper'),
            'vehicle' => (string) __('Vehicle'),
            default => $ownerType,
        };
    }

    /**
     * Etiqueta legible del tipo de documento: usa el enum conocido de flota y cae al valor crudo si es
     * un tipo libre no previsto.
     */
    private function documentTypeLabel(string $documentType): string
    {
        $known = FleetDocumentType::tryFrom($documentType);

        return $known !== null ? (string) __($known->label()) : $documentType;
    }

    /**
     * URL al recurso del dueño del documento; null si el tipo no tiene recurso o la ruta no resuelve.
     */
    private function ownerUrl(string $ownerType, string $ownerId): ?string
    {
        $resource = match ($ownerType) {
            'driver' => DriverResource::class,
            'helper' => HelperResource::class,
            'vehicle' => VehicleResource::class,
            default => null,
        };

        if ($resource === null) {
            return null;
        }

        try {
            return $resource::getUrl('view', ['record' => $ownerId]);
        } catch (Throwable) {
            return null;
        }
    }
}
