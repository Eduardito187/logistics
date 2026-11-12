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

        // Un único punto de salida: el registro devuelve [clave_flash, mensaje] y acá se emite el redirect.
        [$key, $message] = $this->record($shipment, $user, $data);

        return back()->with($key, $message);
    }

    /**
     * Registra la prueba de entrega del primer producto del envío por el dominio y devuelve el flash a mostrar.
     *
     * @param  array<string, mixed>        $data
     * @return array{0: string, 1: string}
     */
    private function record(string $shipment, User $user, array $data): array
    {
        // Primer producto del envío (la prueba de entrega se ancla a una unidad del envío).
        $unitId = DB::table('shipment_units')
            ->where('shipment_id', $shipment)
            ->orderBy('line_id')->orderBy('unit_index')->orderBy('piece_index')
            ->value('id');

        if (!is_string($unitId) || $unitId === '') {
            return ['error', 'No se encontraron productos para este pedido.'];
        }

        try {
            $this->bus->dispatch(new RecordProofOfDelivery(
                shipmentId: $shipment,
                unitId: $unitId,
                confirmation: is_string($data['confirmation'] ?? null) ? $data['confirmation'] : '',
                photoUrl: null,
                photoPath: null,
                // Sin señal no hay posición: null, nunca 0,0 (contaminaría la auditoría geográfica).
                lat: is_numeric($data['lat'] ?? null) ? (float) $data['lat'] : null,
                lng: is_numeric($data['lng'] ?? null) ? (float) $data['lng'] : null,
                deviceId: 'field-app',
                userId: (string) $user->getKey(),
                occurredAt: (new DateTimeImmutable('now'))->format(DATE_ATOM),
                idempotencyKey: 'fieldapp-pod-'.$shipment,
            ));
        } catch (Throwable $e) {
            return ['error', 'No se pudo confirmar la entrega: '.$e->getMessage()];
        }

        return ['success', 'Entrega confirmada.'];
    }
}
