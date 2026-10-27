<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Illuminate\Support\HtmlString;
use Logistics\Labels\Application\Command\EmitShipmentLabels\EmitShipmentLabels;
use Logistics\Labels\Application\Command\PreEmitOrderLabels\PreEmitOrderLabels;
use Logistics\Labels\Application\Command\ReprintOrderLabels\ReprintOrderLabels;
use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Labels\Application\Query\GetPublicKeysBundle\GetPublicKeysBundle;
use Logistics\Labels\Application\ReadModel\PublicKeyBundleView;
use Logistics\Labels\Domain\Exception\DuplicateLabelException;
use Logistics\Labels\Domain\Exception\InvalidLabelException;
use Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Listado de etiquetas emitidas y HOGAR DE LOS FLUJOS del contexto.
 *
 * Las etiquetas no se crean/editan a mano (son firmadas e inmutables, ADR 004). En su lugar, el
 * encabezado expone los CASOS DE USO reales como acciones que despachan los comandos al CommandBus
 * (invariantes + outbox transaccional) y consultan por el QueryBus:
 *
 *  - Emit labels        → EmitShipmentLabels   (emite el lote de un pedido)
 *  - Pre-emit labels    → PreEmitOrderLabels   (deja el lote listo al planificar; idempotente)
 *  - Reprint labels     → ReprintOrderLabels   (revoca las vigentes y emite un lote nuevo)
 *  - Signing keys        → GetPublicKeysBundle  (ver las claves públicas vigentes)
 *
 * Nunca se escribe el Record directamente para operaciones de negocio.
 */
final class ListEmittedLabels extends ListRecords
{
    protected static string $resource = EmittedLabelResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->emitLabelsAction(),
            $this->preEmitLabelsAction(),
            $this->reprintLabelsAction(),
            $this->publicKeysAction(),
        ];
    }

    /**
     * Emitir el lote de etiquetas de un pedido (EmitShipmentLabels). Una etiqueta por unidad física de
     * cada línea. Si el pedido ya tiene etiquetas vigentes, el dominio corta con DuplicateLabelException.
     */
    private function emitLabelsAction(): Action
    {
        return Action::make('emitLabels')
            ->label(__('Emit labels'))
            ->icon('ds-qr-code')
            ->color('primary')
            ->modalHeading(__('Emit shipment labels'))
            ->modalDescription(__('Signs and renders one label per physical package of each order line.'))
            ->modalSubmitActionLabel(__('Emit labels'))
            ->schema(self::orderFormSchema())
            ->action(function (array $data): void {
                try {
                    app(CommandBus::class)->dispatch(new EmitShipmentLabels(
                        orderNumber: (string) $data['order_number'],
                        origin: (string) $data['origin'],
                        destination: (string) $data['destination'],
                        lines: self::linesFrom($data),
                    ));
                } catch (DuplicateLabelException | InvalidLabelException $e) {
                    Notification::make()
                        ->title(__('Labels could not be emitted'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Labels emitted'))
                    ->body(__('Labels for order :order were signed, rendered and recorded.', ['order' => $data['order_number']]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Pre-emitir las etiquetas de un pedido al planificar (PreEmitOrderLabels). Idempotente: si el
     * pedido ya tiene etiquetas vigentes no hace nada, para que el relay del outbox pueda reentregar.
     */
    private function preEmitLabelsAction(): Action
    {
        return Action::make('preEmitLabels')
            ->label(__('Pre-emit labels'))
            ->icon('ds-clock')
            ->color('gray')
            ->modalHeading(__('Pre-emit order labels'))
            ->modalDescription(__('Prepares the signed labels ahead of picking so the app can print them offline. Idempotent.'))
            ->modalSubmitActionLabel(__('Pre-emit labels'))
            ->schema(self::orderFormSchema())
            ->action(function (array $data): void {
                try {
                    app(CommandBus::class)->dispatch(new PreEmitOrderLabels(
                        orderNumber: (string) $data['order_number'],
                        origin: (string) $data['origin'],
                        destination: (string) $data['destination'],
                        lines: self::linesFrom($data),
                    ));
                } catch (InvalidLabelException $e) {
                    Notification::make()
                        ->title(__('Labels could not be pre-emitted'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Labels pre-emitted'))
                    ->body(__('Order :order now has its labels ready (no-op if they already existed).', ['order' => $data['order_number']]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Reimprimir las etiquetas de un pedido (ReprintOrderLabels): revoca las vigentes (quedan en la base
     * para auditoría) y emite un lote nuevo con `id` nuevos. Lleva un motivo para dejar rastro.
     */
    private function reprintLabelsAction(): Action
    {
        return Action::make('reprintLabels')
            ->label(__('Reprint labels'))
            ->icon('ds-printer')
            ->color('warning')
            ->modalHeading(__('Reprint order labels'))
            ->modalDescription(__('Revokes the current labels of the order (kept for audit) and emits a fresh batch.'))
            ->modalSubmitActionLabel(__('Reprint labels'))
            ->schema([
                ...self::orderFormSchema(),
                TextInput::make('reason')
                    ->label(__('Reprint reason'))
                    ->placeholder(__('e.g. Damaged label, repacked box'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                try {
                    app(CommandBus::class)->dispatch(new ReprintOrderLabels(
                        orderNumber: (string) $data['order_number'],
                        origin: (string) $data['origin'],
                        destination: (string) $data['destination'],
                        lines: self::linesFrom($data),
                        reason: (string) $data['reason'],
                    ));
                } catch (InvalidLabelException $e) {
                    Notification::make()
                        ->title(__('Labels could not be reprinted'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Labels reprinted'))
                    ->body(__('Order :order labels were revoked and re-emitted.', ['order' => $data['order_number']]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Ver el bundle de claves públicas vigentes (GetPublicKeysBundle). Solo lectura: la app las usa para
     * verificar firmas de QR sin conexión. Se resuelve por el QueryBus (CQRS).
     */
    private function publicKeysAction(): Action
    {
        return Action::make('publicKeys')
            ->label(__('Signing keys'))
            ->icon('ds-key')
            ->color('gray')
            ->modalHeading(__('Public signing keys'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->modalContent(fn (): HtmlString => self::publicKeysModal());
    }

    /**
     * Campos comunes de los flujos que emiten etiquetas de un pedido: número, origen, destino y líneas.
     * El orden de las líneas fija el número de línea (el handler respeta el orden del repeater).
     *
     * @return array<int, Component>
     */
    private static function orderFormSchema(): array
    {
        return [
            TextInput::make('order_number')
                ->label(__('Order number'))
                ->placeholder(__('e.g. DM-260123456'))
                ->required()
                ->maxLength(40),
            TextInput::make('origin')
                ->label(__('Origin node'))
                ->placeholder(__('e.g. CD-SCZ'))
                ->required()
                ->maxLength(20),
            TextInput::make('destination')
                ->label(__('Destination'))
                ->helperText(__('Use "C" for the final customer, or a node code (e.g. CD-LPZ).'))
                ->default('C')
                ->required()
                ->maxLength(20),
            Repeater::make('lines')
                ->label(__('Order lines'))
                ->helperText(__('One label is emitted per physical package: quantity × pieces per unit.'))
                ->schema([
                    TextInput::make('sku')
                        ->label(__('SKU'))
                        ->required()
                        ->maxLength(40),
                    TextInput::make('name')
                        ->label(__('Product name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('quantity')
                        ->label(__('Units'))
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    TextInput::make('pieces_per_unit')
                        ->label(__('Pieces per unit'))
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                ])
                ->columns(2)
                ->minItems(1)
                ->default([])
                ->addActionLabel(__('Add line')),
        ];
    }

    /**
     * Traduce las líneas del formulario a los DTOs de línea del contexto, en orden (igual que el
     * FormRequest de la API). La posición de cada línea fija su número de línea.
     *
     * @param  array<string, mixed> $data
     * @return list<OrderLineInput>
     */
    private static function linesFrom(array $data): array
    {
        /** @var list<array{sku: string, name: string, quantity: int|string, pieces_per_unit: int|string}> $lines */
        $lines = array_values($data['lines'] ?? []);

        return array_map(
            static fn (array $line): OrderLineInput => new OrderLineInput(
                sku: (string) $line['sku'],
                name: (string) $line['name'],
                quantity: (int) $line['quantity'],
                piecesPerUnit: (int) ($line['pieces_per_unit'] ?? 1),
            ),
            $lines,
        );
    }

    /**
     * Contenido del modal de claves públicas: pide el bundle por el QueryBus y lo muestra legible
     * (clave activa destacada + cada id con su clave pública en base64). Solo lectura.
     */
    private static function publicKeysModal(): HtmlString
    {
        /** @var PublicKeyBundleView $bundle */
        $bundle = app(QueryBus::class)->ask(new GetPublicKeysBundle);
        $data = $bundle->toArray();

        if ($data['keys'] === []) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('No signing keys are configured.')).'</p>');
        }

        $activeId = $data['active_key_id'];
        $rows = '';
        foreach ($data['keys'] as $key) {
            $isActive = $key['id'] === $activeId;
            $badge = $isActive
                ? '<span class="text-xs font-medium text-green-600">'.e(__('active')).'</span>'
                : '<span class="text-xs text-gray-400">'.e(__('accepted')).'</span>';
            $rows .= '<tr>'
                .'<td class="py-1 pr-4 align-top">'.e(__('Key #')).e((string) $key['id']).' '.$badge.'</td>'
                .'<td class="py-1 font-mono text-xs break-all">'.e($key['public_key']).'</td>'
                .'</tr>';
        }

        return new HtmlString(
            '<p class="text-sm mb-3">'.__('The app uses these Ed25519 public keys to verify QR signatures offline. It picks the key by the :field field of each label.', ['field' => '<code>k</code>'])
            .'</p>'
            .'<table class="w-full text-sm"><thead><tr class="text-left border-b">'
            .'<th class="py-1 pr-4">'.e(__('Key')).'</th><th class="py-1">'.e(__('Public key (base64)')).'</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>',
        );
    }
}
