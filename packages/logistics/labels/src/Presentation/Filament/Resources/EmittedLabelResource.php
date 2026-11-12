<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Resources;

use App\Filament\Support\RequestScopedQuery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Logistics\Labels\Application\Query\GetShipmentLabels\GetShipmentLabels;
use Logistics\Labels\Application\ReadModel\EmittedLabelView;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource\Pages\ListEmittedLabels;
use Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource\Pages\ViewEmittedLabel;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de las etiquetas QR emitidas (auditoría y reimpresión).
 *
 * Es SOLO LECTURA sobre la fila: una etiqueta emitida está firmada y es inmutable (ADR 004). Se emite
 * por API, por el worker de pre-emisión o por los FLUJOS del encabezado del listado (que despachan los
 * comandos reales al CommandBus), nunca editando la fila a mano desde el admin. Por eso no hay páginas
 * de crear ni editar, y create/edit/delete están deshabilitados.
 *
 * Datos legibles: en lugar de los UUID/ULID crudos, la tabla muestra pedido, SKU, línea y la unidad
 * "N of M" (decodificados del QR firmado por los accessors del Record), el estado como badge y la ruta
 * origen → destino. El `label_id` (ULID) y la pk de almacenamiento quedan ocultos por defecto.
 *
 * Nota de wiring: el panel descubre recursos en app/Filament/Resources. Para que este recurso del
 * package aparezca, el integrador agrega su ruta/namespace al AdminPanelProvider
 * (discoverResources in: .../labels/src/Presentation/Filament/Resources for this namespace).
 */
final class EmittedLabelResource extends Resource
{
    // Fuera del menú: depende de la entidad (pedido/envío); su hogar es la ficha 360 de esa entidad
    // (sección «Gestión»), que enlaza a su lista. No es un ítem suelto del menú.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    use OfficeOnly;

    protected static ?string $model = EmittedLabelRecord::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedQrCode;

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationSort(): int
    {
        return 40;
    }

    public static function getNavigationLabel(): string
    {
        return __('Emitted labels');
    }

    public static function getModelLabel(): string
    {
        return __('emitted label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('emitted labels');
    }

    /**
     * Título humano del registro: pedido + SKU + unidad "N of M", nunca el UUID/ULID. Cae al ULID solo
     * si el QR no se pudo decodificar (dato corrupto), para no quedar sin título.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof EmittedLabelRecord) {
            return null;
        }

        $parts = array_values(array_filter([
            $record->order_id,
            $record->sku,
            $record->unit_label !== null ? __('unit ').$record->unit_label : null,
        ], static fn (?string $part): bool => $part !== null && $part !== ''));

        return $parts === [] ? $record->label_id : implode(' · ', $parts);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Acota a la entidad padre al abrir desde su ficha 360 (?order=<id>).
            ->modifyQueryUsing(RequestScopedQuery::by('order', 'order_id'))
            ->columns([
                TextColumn::make('order_id')
                    ->label(__('Order'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label(__('SKU'))
                    // El SKU es la identidad del producto en la etiqueta: sin él cae a "Product", nunca "—".
                    ->placeholder(__('Product')),
                TextColumn::make('line_number')
                    ->label(__('Line'))
                    ->placeholder('—'),
                TextColumn::make('unit_label')
                    ->label(__('Unit'))
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),
                TextColumn::make('piece_label')
                    ->label(__('Piece'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('route')
                    ->label(__('Route'))
                    // Ruta legible origen → destino (Customer para dst = C), armada de los campos del QR.
                    ->state(fn (EmittedLabelRecord $record): string => trim(
                        ($record->origin ?? '—').' → '.($record->destination_label ?? '—'),
                    ))
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('Emitted at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('period')
                    ->label(__('Date range'))
                    ->schema([
                        DatePicker::make('from')->label(__('From'))->native(false)->default(now()->subDays(30)),
                        DatePicker::make('until')->label(__('To'))->native(false),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        /** @var \Illuminate\Database\Eloquent\Builder<EmittedLabelRecord> $query */
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;
                        if (is_string($from) && $from !== '') {
                            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
                        }
                        if (is_string($until) && $until !== '') {
                            $query->where('created_at', '<', Carbon::parse($until)->addDay()->startOfDay());
                        }

                        return $query;
                    })
                    ->indicateUsing(static fn (array $data): ?string => ($data['from'] ?? null) ? (string) __('Date range') : null),
                // Filtra por si la etiqueta YA se imprimió (bitácora de ciclo de vida): impresas vs pendientes.
                TernaryFilter::make('printed')
                    ->label(__('Print status'))
                    ->placeholder(__('All labels'))
                    ->trueLabel(__('Printed only'))
                    ->falseLabel(__('Pending (not printed)'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('label_id', self::printedLabelIds()),
                        false: fn (Builder $query): Builder => $query->whereNotIn('label_id', self::printedLabelIds()),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('key_id')
                    ->label(__('Signing key'))
                    ->native(false)
                    ->searchable()
                    // Datos reales: las claves efectivamente usadas en etiquetas emitidas.
                    ->options(fn (): array => EmittedLabelRecord::query()
                        ->distinct()
                        ->orderBy('key_id')
                        ->pluck('key_id', 'key_id')
                        ->map(fn (int $keyId): string => __('Key #').$keyId)
                        ->all()),
                // Rango de fecha de emisión para acotar auditorías y reimpresiones.
                Filter::make('emitted_at')
                    ->schema([
                        DatePicker::make('emitted_from')
                            ->label(__('Emitted from'))
                            ->native(false),
                        DatePicker::make('emitted_until')
                            ->label(__('Emitted until'))
                            ->native(false),
                    ])
                    // Rango sobre timestamptz en vez de whereDate() (auditoría M-2): whereDate envuelve
                    // la columna en DATE() y anula el índice. "Desde" es el inicio del día; "hasta" es el
                    // inicio del día siguiente (rango medio-abierto) para incluir el día elegido completo.
                    ->query(function (Builder $query, array $data): Builder {
                        /** @var \Illuminate\Database\Eloquent\Builder<EmittedLabelRecord> $query */
                        $from = $data['emitted_from'] ?? null;
                        $until = $data['emitted_until'] ?? null;

                        if (is_string($from) && $from !== '') {
                            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
                        }

                        if (is_string($until) && $until !== '') {
                            $query->where('created_at', '<', Carbon::parse($until)->startOfDay()->addDay());
                        }

                        return $query;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                // Imprimir ESTA etiqueta (hoja imprimible, normal o de pegatinas) — registra quién imprimió.
                ActionGroup::make([
                    Action::make('printNormal')
                        ->label(__('Normal printer'))
                        ->icon('heroicon-o-printer')
                        ->url(fn (EmittedLabelRecord $record): string => route('cockpit.labels.print', ['ids' => $record->label_id, 'printer' => 'normal']), shouldOpenInNewTab: true),
                    Action::make('printSticker')
                        ->label(__('Sticker printer'))
                        ->icon('heroicon-o-tag')
                        ->url(fn (EmittedLabelRecord $record): string => route('cockpit.labels.print', ['ids' => $record->label_id, 'printer' => 'sticker']), shouldOpenInNewTab: true),
                ])
                    ->label(__('Print'))
                    ->icon('heroicon-o-printer')
                    ->button(),
                // Flujo de lectura: obtener TODAS las etiquetas del pedido de esta fila y su ZPL concatenado,
                // listo para volver a imprimir en la Zebra. Se resuelve por el QueryBus (CQRS), nunca leyendo
                // el Record directo. Las etiquetas son permanentes: se reimprime la MISMA, no se re-emite.
                Action::make('orderLabels')
                    ->label(__('Order labels & ZPL'))
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(fn (EmittedLabelRecord $record): string => __('Labels for order ').$record->order_id)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (EmittedLabelRecord $record): HtmlString => self::orderLabelsModal($record->order_id)),
            ])
            ->toolbarActions([
                // IMPRIMIR VARIAS de golpe: junta las etiquetas vigentes seleccionadas en una sola hoja
                // imprimible (normal o de pegatinas) y registra la impresión de cada una.
                BulkAction::make('printSelected')
                    ->label(__('Print selected'))
                    ->icon('heroicon-o-printer')
                    ->schema([
                        Select::make('printer')
                            ->label(__('Printer'))
                            ->native(false)
                            ->options(['normal' => __('Normal printer'), 'sticker' => __('Sticker printer')])
                            ->default('normal')
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data): RedirectResponse {
                        $ids = $records->pluck('label_id')->map(fn (mixed $v): string => (string) $v)->all();

                        return redirect()->to(route('cockpit.labels.print', [
                            'ids' => implode(',', $ids),
                            'printer' => ($data['printer'] ?? 'normal') === 'sticker' ? 'sticker' : 'normal',
                        ]));
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->deferLoading()
            ->paginationPageOptions([25, 50, 100])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * label_ids que YA tienen un evento de impresión en la bitácora (para el filtro «impresa/pendiente»).
     * Tolerante: lista vacía si la tabla no existe todavía.
     *
     * @return array<int, string>
     */
    private static function printedLabelIds(): array
    {
        try {
            return DB::table('label_events')
                ->where('event', 'printed')
                ->distinct()
                ->pluck('label_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Label'))
                ->schema([
                    TextEntry::make('order_id')->label(__('Order')),
                    TextEntry::make('sku')->label(__('SKU'))->placeholder(__('Product')),
                    TextEntry::make('line_number')->label(__('Line'))->placeholder('—'),
                    TextEntry::make('unit_label')->label(__('Unit (N of M)'))->badge()->color('info')->placeholder('—'),
                    TextEntry::make('piece_label')->label(__('Piece (N of M)'))->placeholder('—'),
                    TextEntry::make('origin')->label(__('Origin'))->placeholder('—'),
                    TextEntry::make('destination_label')->label(__('Destination'))->placeholder('—'),
                    TextEntry::make('created_at')->label(__('Emitted at'))->dateTime(),
                ])
                ->columns(3),
            Section::make(__('Label preview'))
                ->description(__('Scannable image of the label, rendered on-premise with the configured symbology (QR, Zebra, or both). The unique label code is always shown below.'))
                ->schema([
                    ViewEntry::make('preview')
                        ->hiddenLabel()
                        ->view('filament.labels.label-preview')
                        ->columnSpanFull(),
                ]),
            // Control total del ciclo de vida: quién generó/imprimió y cada escaneo (etapa + actor + hora).
            Section::make(__('Lifecycle'))
                ->description(__('Full audit trail: who generated, printed and scanned (by stage) this label, and when.'))
                ->schema([
                    ViewEntry::make('lifecycle')
                        ->hiddenLabel()
                        ->view('filament.labels.label-lifecycle')
                        ->columnSpanFull(),
                ]),
            Section::make(__('Signed payload'))
                ->description(__('Signed QR content and ZPL — for auditing and printing only.'))
                ->collapsed()
                ->schema([
                    TextEntry::make('signed_json')
                        ->label(__('QR content (signed)'))
                        ->copyable()
                        ->columnSpanFull(),
                    TextEntry::make('zpl')
                        ->label(__('ZPL (Zebra)'))
                        ->copyable()
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListEmittedLabels::route('/'),
            'view' => ViewEmittedLabel::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    /**
     * Contenido del modal "Order labels & ZPL": pide las etiquetas del pedido por el QueryBus y arma una
     * tabla legible (línea, SKU, unidad "N of M", estado) más el ZPL vigente concatenado para la Zebra.
     *
     * Es presentación pura: no valida firmas ni escribe nada; solo muestra lo ya emitido.
     */
    private static function orderLabelsModal(string $orderNumber): HtmlString
    {
        /** @var list<EmittedLabelView> $views */
        $views = app(QueryBus::class)->ask(new GetShipmentLabels($orderNumber));

        if ($views === []) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('No labels have been emitted for this order yet.')).'</p>');
        }

        $rows = '';
        $allZpl = [];
        foreach ($views as $view) {
            $unit = self::qrFractionLabel($view->qrContent, 'u');
            $rows .= '<tr>'
                .'<td class="py-1 pr-4">'.e((string) $view->lineNumber).'</td>'
                .'<td class="py-1 pr-4">'.e($view->sku !== '' ? $view->sku : __('Product')).'</td>'
                .'<td class="py-1 pr-4">'.e($unit ?? '—').'</td>'
                .'</tr>';

            $allZpl[] = $view->zpl;
        }

        $table = '<table class="w-full text-sm mb-4">'
            .'<thead><tr class="text-left border-b">'
            .'<th class="py-1 pr-4">'.e(__('Line')).'</th><th class="py-1 pr-4">'.e(__('SKU')).'</th>'
            .'<th class="py-1 pr-4">'.e(__('Unit')).'</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>';

        $zplBlock = '<p class="text-sm font-medium mb-1">'.e(__('ZPL (ready for the Zebra printer):')).'</p>'
            .'<pre class="text-xs p-3 rounded bg-gray-100 dark:bg-gray-800 overflow-auto max-h-80">'
            .e(implode("\n", $allZpl)).'</pre>';

        return new HtmlString($table.$zplBlock);
    }

    /**
     * Extrae del contenido firmado del QR la fracción "N of M" de la clave pedida (`u` o `pc`).
     */
    private static function qrFractionLabel(string $qrContent, string $key): ?string
    {
        $data = json_decode($qrContent, true);
        if (!is_array($data)) {
            return null;
        }
        $fraction = $data[$key] ?? null;
        if (!is_array($fraction) || !isset($fraction[0], $fraction[1]) || !is_int($fraction[0]) || !is_int($fraction[1])) {
            return null;
        }

        return sprintf('%d of %d', $fraction[0], $fraction[1]);
    }
}
