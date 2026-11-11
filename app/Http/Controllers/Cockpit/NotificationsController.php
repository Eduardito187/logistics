<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cockpit;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Domain\Contract\DocumentExpiryPolicy;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Command\AcknowledgeAlert\AcknowledgeAlert;
use Logistics\Telemetry\Application\Query\ListAlerts\ListAlerts;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use Logistics\Telemetry\Presentation\Filament\Pages\LiveFleetMap;
use Logistics\Telemetry\Presentation\Filament\Support\VehiclePlates;
use Throwable;

/**
 * Centro de NOTIFICACIONES global del admin (campana del header). Unifica las "alertas" operativas en un
 * solo lugar accionable, en vez de tenerlas como ítems sueltos del menú:
 *   - Alertas de telemetría sin reconocer (posible mal uso de la flota) → con acción "Reconocer".
 *   - Documentos de flota por vencer / vencidos (SOAT, inspección) → enlazan a la ficha del dueño.
 *
 * Solo alcance de OFICINA (admin/supervisor). Lectura por el QueryBus; las acciones por el CommandBus.
 * Nada de UUID visible: placa y dueño resueltos a etiqueta legible.
 */
final class NotificationsController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly CommandBus $commandBus,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeOffice($request);

        $items = [...$this->alertItems(), ...$this->documentItems()];

        return response()->json([
            'count' => count($items),
            'items' => $items,
        ]);
    }

    public function acknowledge(Request $request, string $alertId): JsonResponse
    {
        $user = $this->authorizeOffice($request);

        try {
            $this->commandBus->dispatch(new AcknowledgeAlert(alertId: $alertId, by: (string) $user->email));
        } catch (Throwable) {
            return response()->json(['ok' => false], 422);
        }

        return response()->json(['ok' => true]);
    }

    private function authorizeOffice(Request $request): User
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->hasAnyRole(Role::Admin, Role::Supervisor)) {
            abort(403);
        }

        return $user;
    }

    /**
     * Alertas de telemetría sin reconocer como notificaciones accionables.
     *
     * @return list<array{id: string, kind: string, title: string, subtitle: string, tone: string, when: string, url: string, ackable: bool}>
     */
    private function alertItems(): array
    {
        try {
            /** @var list<AlertView> $alerts */
            $alerts = $this->queryBus->ask(new ListAlerts(acknowledged: false));
        } catch (Throwable) {
            return [];
        }

        $mapUrl = $this->safeUrl(static fn (): string => LiveFleetMap::getUrl());

        return array_map(function (AlertView $a) use ($mapUrl): array {
            return [
                'id' => $a->id,
                'kind' => 'alert',
                'title' => (VehiclePlates::label($a->vehicleId) ?? 'Vehículo').' · '.$this->humanType($a->type),
                'subtitle' => $this->severityLabel($a->severity),
                'tone' => $this->severityTone($a->severity),
                'when' => $a->occurredAt,
                'url' => $mapUrl,
                'ackable' => true,
            ];
        }, $alerts);
    }

    /**
     * Documentos de flota por vencer / vencidos como notificaciones que enlazan a la ficha del dueño.
     *
     * @return list<array{id: string, kind: string, title: string, subtitle: string, tone: string, when: string, url: string, ackable: bool}>
     */
    private function documentItems(): array
    {
        try {
            $days = app(DocumentExpiryPolicy::class)->alertWindowDays();
            /** @var list<ExpiringDocumentView> $views */
            $views = $this->queryBus->ask(new ListExpiringDocuments($days));
        } catch (Throwable) {
            return [];
        }

        return array_map(fn (ExpiringDocumentView $v): array => [
            'id' => $v->ownerType.':'.$v->ownerId.':'.$v->documentType,
            'kind' => 'document',
            'title' => $v->ownerLabel.' · '.$this->humanType($v->documentType),
            'subtitle' => $v->expired ? 'Documento vencido' : 'Por vencer ('.$v->expiresOn.')',
            'tone' => $v->expired ? 'danger' : 'warning',
            'when' => $v->expiresOn,
            'url' => $this->ownerUrl($v->ownerType, $v->ownerId),
            'ackable' => false,
        ], $views);
    }

    /** URL de la ficha 360 del dueño del documento (vehículo/chofer/ayudante), o el panel si no se resuelve. */
    private function ownerUrl(string $ownerType, string $ownerId): string
    {
        return $this->safeUrl(static function () use ($ownerType, $ownerId): string {
            return match ($ownerType) {
                'vehicle' => VehicleResource::getUrl('view', ['record' => $ownerId]),
                'driver' => DriverResource::getUrl('view', ['record' => $ownerId]),
                'helper' => HelperResource::getUrl('view', ['record' => $ownerId]),
                default => '/admin',
            };
        });
    }

    /** @param callable(): string $resolver */
    private function safeUrl(callable $resolver): string
    {
        try {
            return $resolver();
        } catch (Throwable) {
            return '/admin';
        }
    }

    private function humanType(string $type): string
    {
        return ucfirst(str_replace('_', ' ', strtolower($type)));
    }

    private function severityLabel(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical', 'high' => 'Crítico',
            'warning', 'medium' => 'Advertencia',
            default => 'Info',
        };
    }

    private function severityTone(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical', 'high' => 'danger',
            'warning', 'medium' => 'warning',
            default => 'info',
        };
    }
}
