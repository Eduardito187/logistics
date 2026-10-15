<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Shipments;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * App web (Inertia) — módulo Pedidos. Lee el read-side de shipments para listar los pedidos recibidos y
 * mostrar su detalle con líneas de producto, bultos y, si hay coordenadas, el mapa de la dirección.
 * Solo lectura; las escrituras de negocio siguen en el admin/API.
 */
final class ShipmentController extends Controller
{
    /**
     * Listado de pedidos recibidos.
     */
    public function index(): Response
    {
        $shipments = ShipmentRecord::query()
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(static fn (ShipmentRecord $shipment): array => [
                'id' => $shipment->getKey(),
                'external_id' => $shipment->external_id,
                'source' => (string) $shipment->source,
                'customer' => $shipment->customer_name,
                'status' => (string) $shipment->status,
                'confidence' => (int) $shipment->address_confidence,
                'confidence_level' => (string) $shipment->address_confidence_level,
            ])
            ->all();

        return Inertia::render('Shipments/Index', [
            'shipments' => $shipments,
        ]);
    }

    /**
     * Detalle de un pedido: cabecera legible, líneas de producto, bultos y datos del mapa de la
     * dirección (un solo punto) cuando el pedido tiene lat/lng.
     */
    public function show(Request $request, string $shipment): Response
    {
        $record = ShipmentRecord::query()
            ->with([
                'lines',
                'units' => static fn ($q) => $q->with('line')->orderBy('unit_index'),
            ])
            ->findOrFail($shipment);

        $lines = $record->lines
            ->map(static fn (ShipmentLineRecord $line): array => [
                'id' => $line->getKey(),
                'sku' => $line->sku,
                'product_name' => $line->product_name,
                'image' => $line->product_image_url !== null && $line->product_image_url !== ''
                    ? $line->product_image_url
                    : null,
                'quantity' => (int) $line->quantity,
                'unit_price' => $line->unit_price,
                'currency' => $line->unit_price_currency ?? 'BOB',
            ])
            ->all();

        $units = $record->units
            ->map(static fn (PhysicalUnitRecord $unit): array => [
                'id' => $unit->getKey(),
                'product_name' => $unit->line?->product_name,
                'sku' => $unit->line?->sku,
                'unit_index' => (int) $unit->unit_index,
                'unit_total' => (int) $unit->unit_total,
                'piece_index' => (int) $unit->piece_index,
                'piece_total' => (int) $unit->piece_total,
            ])
            ->all();

        $lat = $this->coord($record->lat);
        $lng = $this->coord($record->lng);
        $hasPoint = $lat !== null && $lng !== null;

        // El mapa reutiliza RouteMap con una sola parada: la dirección de entrega del pedido.
        $map = $hasPoint
            ? [
                'stops' => [[
                    'seq' => 1,
                    'lat' => $lat,
                    'lng' => $lng,
                    'order' => $record->external_id,
                    'customer' => $record->customer_name,
                    'status' => (string) $record->status,
                ]],
                'planned' => [],
                'actual' => [],
            ]
            : null;

        return Inertia::render('Shipments/Show', [
            'shipment' => [
                'id' => $record->getKey(),
                'external_id' => $record->external_id,
                'source' => (string) $record->source,
                'status' => (string) $record->status,
                'customer' => $record->customer_name,
                'customer_document_id' => $record->customer_document_id,
                'customer_phone' => $record->customer_phone,
                'customer_email' => $record->customer_email,
                'address_text' => $record->address_text,
                'address_reference' => $record->address_reference,
                'confidence' => (int) $record->address_confidence,
                'confidence_level' => (string) $record->address_confidence_level,
                'delivery_date' => $record->delivery_date,
                'delivery_window_start' => $record->delivery_window_start,
                'delivery_window_end' => $record->delivery_window_end,
            ],
            'lines' => $lines,
            'units' => $units,
            'map' => $map,
        ]);
    }

    /**
     * Normaliza una coordenada a float, o null si no es válida.
     */
    private function coord(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
