<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Shared\Application\Command\CommandBus;
use Throwable;

/**
 * «Confirmar entrega» desde la app de campo del chofer: registra la PRUEBA DE ENTREGA de una parada por el
 * comando de dominio (RecordProofOfDelivery) vía el CommandBus — nunca escribe el read-model directo. Es
 * idempotente por envío. Devuelve flash de éxito/error para que la UI lo muestre sin recargar.
 */
final class DriverDeliveryController
{
    public function __construct(
        private readonly CommandBus $bus,
    ) {}

    public function confirm(Request $request, string $shipment): RedirectResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:160'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $user = Auth::user();
        if (!$user instanceof User || $user->driver_id === null) {
            return back()->with('error', 'Sesión de chofer no válida.');
        }

        // Primer bulto del envío (la prueba de entrega se ancla a una unidad del envío).
        $unitId = DB::table('shipment_units')
            ->where('shipment_id', $shipment)
            ->orderBy('line_id')->orderBy('unit_index')->orderBy('piece_index')
            ->value('id');

        if (!is_string($unitId) || $unitId === '') {
            return back()->with('error', 'No se encontraron bultos para este pedido.');
        }

        try {
            $this->bus->dispatch(new RecordProofOfDelivery(
                shipmentId: $shipment,
                unitId: (string) $unitId,
                confirmation: $data['confirmation'],
                photoUrl: null,
                photoPath: null,
                lat: (float) ($data['lat'] ?? 0.0),
                lng: (float) ($data['lng'] ?? 0.0),
                deviceId: 'field-app',
                userId: (string) $user->getKey(),
                occurredAt: (new DateTimeImmutable('now'))->format(DATE_ATOM),
                idempotencyKey: 'fieldapp-pod-'.$shipment,
            ));
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo confirmar la entrega: '.$e->getMessage());
        }

        return back()->with('success', 'Entrega confirmada.');
    }
}
