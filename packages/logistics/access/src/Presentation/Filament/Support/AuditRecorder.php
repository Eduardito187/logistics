<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Logistics\Access\Application\Command\RecordAuditEntry\RecordAuditEntry;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puente entre las acciones sensibles del admin Filament y la bitácora de auditoría.
 *
 * Resuelve el autor (cuenta administradora autenticada) y la IP de la petición, y despacha el comando
 * RecordAuditEntry al bus CQRS. La escritura de la entrada y su publicación al outbox las hace el
 * handler; aquí solo se arma la intención desde el contexto web. Es un helper de Presentación: puede
 * leer Auth y request() (nunca lo haría el dominio).
 */
final class AuditRecorder
{
    /**
     * Deja constancia de una acción sensible.
     *
     * @param array<string, mixed> $context
     */
    public static function record(
        string $action,
        string $subjectType,
        ?string $subjectId = null,
        array $context = [],
    ): void {
        [$actorId, $actorLabel] = self::actor();

        app(CommandBus::class)->dispatch(new RecordAuditEntry(
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            actorId: $actorId,
            actorLabel: $actorLabel,
            context: $context,
            ipAddress: request()->ip(),
        ));
    }

    /**
     * Identidad del autor de la acción a partir de la sesión del admin: id y etiqueta legible (nombre o
     * email). Si no hay sesión, la acción se atribuye al sistema.
     *
     * @return array{0: string|null, 1: string}
     */
    private static function actor(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return [null, 'System'];
        }

        $actorId = (string) $user->getAuthIdentifier();

        $label = $actorId;
        if ($user instanceof Model) {
            $name = $user->getAttribute('name');
            $email = $user->getAttribute('email');
            if (is_string($name) && trim($name) !== '') {
                $label = $name;
            } elseif (is_string($email) && trim($email) !== '') {
                $label = $email;
            }
        }

        return [$actorId, $label];
    }
}
