<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource\Pages;

use DateTimeImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Access\Application\Service\IntegrationTokenIssuer;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\IntegrationTokenRecord;
use Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource;
use Logistics\Access\Presentation\Filament\Support\AuditRecorder;

/**
 * Alta de un token de integración desde el admin.
 *
 * No persiste con Eloquent ni despacha un comando: usa el servicio IntegrationTokenIssuer, que es el
 * único camino que revela el secreto en claro. El permiso elegido en el formulario (select con datos
 * reales de access_permissions) define el alcance del token. Tras emitirlo se muestra el token en claro
 * UNA sola vez (ver IntegrationTokenResource::revealToken) y luego se redirige a la vista del recurso.
 */
final class CreateIntegrationToken extends CreateRecord
{
    protected static string $resource = IntegrationTokenResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var list<string> $permissionKeys */
        $permissionKeys = array_values(is_array($data['permission_keys'] ?? null) ? $data['permission_keys'] : []);

        $expiresAt = isset($data['expires_at']) && $data['expires_at'] !== ''
            ? new DateTimeImmutable((string) $data['expires_at'])
            : null;

        $view = app(IntegrationTokenIssuer::class)->issue(
            (string) $data['name'],
            $permissionKeys,
            $expiresAt,
            auth()->user()?->email,
        );

        // Revela el secreto en claro una sola vez (notificación persistente con botón de copiar).
        IntegrationTokenResource::revealToken($view);

        AuditRecorder::record('token.issued', 'access.integration_token', $view->id, [
            'name' => (string) $data['name'],
            'permissions' => $permissionKeys,
            'expires_at' => $expiresAt?->format(DATE_ATOM),
        ]);

        // El Issuer sí devuelve el id del token recién emitido: con él se localiza el record para redirigir.
        return IntegrationTokenRecord::query()->whereKey($view->id)->firstOrFail();
    }
}
