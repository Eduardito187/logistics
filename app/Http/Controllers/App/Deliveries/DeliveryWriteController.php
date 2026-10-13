<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Deliveries;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDelivery;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Application\Command\ReportVehicleIncident\ReportVehicleIncident;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Execution\Domain\ValueObject\IncidentSeverity;
use Logistics\Execution\Domain\ValueObject\IncidentType;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Exception\DomainException;

/**
 * App web (Inertia) — ESCRITURAS del módulo Entregas. A diferencia del monitor de solo lectura
 * (DeliveryController), aquí el operador ACTÚA sobre una entrega: registra una entrega fallida, registra
 * la prueba de entrega o reporta un incidente del vehículo.
 *
 * Respeta la arquitectura: Presentation → Application. Nunca toca Infrastructure ni el dominio directo;
 * cada acción despacha un Command por el CommandBus (CQRS). El read-side Eloquent (DeliveryRecord) se usa
 * solo para resolver los identificadores de dominio (shipment_id, vehicle_id) a partir del id de la ruta,
 * no para escribir. Los escaneos son append-only: estas acciones agregan hechos, nunca editan ni borran.
 *
 * El dominio es la fuente de verdad de las reglas (p.ej. una entrega solo puede fallar o entregarse si
 * está cargada o en tránsito); si una regla no se cumple, el handler lanza una DomainException que aquí
 * se traduce a un mensaje flash de error, sin filtrar detalles de capas internas.
 */
final class DeliveryWriteController extends Controller
{
    /**
     * Registra un intento de entrega fallido con su motivo tipificado (FailureReason) y cierra la
     * entrega como fallida. Despacha RecordFailedDelivery, que además avanza la ruta del chofer a la
     * siguiente parada si el camión tiene ruta armada.
     *
     * Nota: el Command RecordFailedDelivery solo acepta el motivo tipificado; no lleva un campo de nota
     * libre, por eso el formulario solo pide el motivo (y el código de la unidad).
     */
    public function fail(Request $request, string $delivery): RedirectResponse
    {
        $record = DeliveryRecord::query()->findOrFail($delivery);

        $reasons = array_map(static fn (FailureReason $r): string => $r->value, FailureReason::cases());

        $data = $request->validate([
            'reason' => ['required', 'string', 'in:'.implode(',', $reasons)],
            'unit_id' => ['required', 'string', 'max:64'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        try {
            app(CommandBus::class)->dispatch(new RecordFailedDelivery(
                shipmentId: (string) $record->shipment_id,
                unitId: (string) $data['unit_id'],
                reason: (string) $data['reason'],
                lat: isset($data['lat']) ? (float) $data['lat'] : 0.0,
                lng: isset($data['lng']) ? (float) $data['lng'] : 0.0,
                deviceId: self::DEVICE,
                userId: $this->userId(),
                occurredAt: now()->toIso8601String(),
                idempotencyKey: (string) Str::uuid(),
            ));
        } catch (DomainException $e) {
            return back()->with('error', 'No se pudo registrar la entrega fallida: '.$e->getMessage());
        }

        return back()->with('success', 'Entrega fallida registrada.');
    }

    /**
     * Registra la prueba de entrega (confirmación del cliente y, opcionalmente, una foto ya subida) y
     * cierra la entrega como entregada. Despacha RecordProofOfDelivery, que además cierra la parada de la
     * ruta del chofer y deja la siguiente como actual si el camión tiene ruta armada.
     */
    public function pod(Request $request, string $delivery): RedirectResponse
    {
        $record = DeliveryRecord::query()->findOrFail($delivery);

        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:512'],
            'unit_id' => ['required', 'string', 'max:64'],
            'photo_url' => ['nullable', 'string', 'url', 'max:2048'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $photoUrl = isset($data['photo_url']) && is_string($data['photo_url']) && $data['photo_url'] !== ''
            ? $data['photo_url']
            : null;

        try {
            app(CommandBus::class)->dispatch(new RecordProofOfDelivery(
                shipmentId: (string) $record->shipment_id,
                unitId: (string) $data['unit_id'],
                confirmation: (string) $data['confirmation'],
                photoUrl: $photoUrl,
                photoPath: null,
                lat: isset($data['lat']) ? (float) $data['lat'] : 0.0,
                lng: isset($data['lng']) ? (float) $data['lng'] : 0.0,
                deviceId: self::DEVICE,
                userId: $this->userId(),
                occurredAt: now()->toIso8601String(),
                idempotencyKey: (string) Str::uuid(),
            ));
        } catch (DomainException $e) {
            return back()->with('error', 'No se pudo registrar la prueba de entrega: '.$e->getMessage());
        }

        return back()->with('success', 'Prueba de entrega registrada.');
    }

    /**
     * Reporta un incidente (avería o accidente) del vehículo de la entrega. Despacha
     * ReportVehicleIncident sobre el vehículo del registro de entrega; la ruta se resuelve sola (ruta
     * abierta vigente del camión). Según la gravedad, el dominio reprograma las paradas pendientes
     * (MAJOR → día siguiente; MINOR → empuja las ETAs) y dispara la reacción en flota/notificaciones por
     * el outbox. Requiere que el camión tenga una ruta abierta; si no, el handler lo rechaza y aquí se
     * muestra el error.
     */
    public function incident(Request $request, string $delivery): RedirectResponse
    {
        $record = DeliveryRecord::query()->findOrFail($delivery);

        $severities = array_map(static fn (IncidentSeverity $s): string => $s->value, IncidentSeverity::cases());
        $types = array_map(static fn (IncidentType $t): string => $t->value, IncidentType::cases());

        $data = $request->validate([
            'severity' => ['required', 'string', 'in:'.implode(',', $severities)],
            'type' => ['required', 'string', 'in:'.implode(',', $types)],
            'note' => ['nullable', 'string', 'max:1000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $note = isset($data['note']) && is_string($data['note']) && trim($data['note']) !== ''
            ? $data['note']
            : null;

        try {
            app(CommandBus::class)->dispatch(new ReportVehicleIncident(
                vehicleId: (string) $record->vehicle_id,
                severity: (string) $data['severity'],
                type: (string) $data['type'],
                routeId: null,
                note: $note,
                lat: isset($data['lat']) ? (float) $data['lat'] : null,
                lng: isset($data['lng']) ? (float) $data['lng'] : null,
                deviceId: self::DEVICE,
                userId: $this->userId(),
                occurredAt: now()->toIso8601String(),
                idempotencyKey: (string) Str::uuid(),
            ));
        } catch (DomainException $e) {
            return back()->with('error', 'No se pudo reportar el incidente: '.$e->getMessage());
        }

        return back()->with('success', 'Incidente del vehículo reportado.');
    }

    /**
     * Dispositivo de origen de estas acciones: la consola web del operador. El dominio exige dejar
     * constancia del dispositivo que origina cada hecho.
     */
    private const DEVICE = 'web-console';

    /**
     * Identidad del operador autenticado que ejecuta la acción, para la evidencia del hecho.
     */
    private function userId(): string
    {
        return (string) (Auth::id() ?? 'web-console');
    }
}
