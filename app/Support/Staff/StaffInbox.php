<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Enums\Role;
use App\Jobs\BroadcastInboxUpdated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Domain\Contract\DocumentExpiryPolicy;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\ListAlerts\ListAlerts;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use Throwable;

/**
 * Bandeja interna del personal (`/app/inbox`): el ÚNICO lugar que sabe cuánto tiene pendiente cada persona
 * y el ÚNICO punto por el que se avisa en tiempo real que ese número cambió.
 *
 * Fuentes de la bandeja:
 *  - avisos internos dirigidos a la persona (`staff_broadcast_recipients`, se marcan leídos);
 *  - solo para la oficina (admin y supervisor): alertas de flota sin reconocer y documentos de flota por
 *    vencer o vencidos. No se "leen": cuentan como pendientes hasta que se resuelven.
 *
 * Quien cambie el no-leído de alguien llama a {@see self::changed()} (personas puntuales) o a
 * {@see self::officeChanged()} (fuentes sin destinatario propio). Ambos solo ENCOLAN: el conteo y la emisión
 * de `inbox.updated` corren en {@see BroadcastInboxUpdated}, fuera del request, y nunca lo hacen fallar.
 */
final class StaffInbox
{
    /** Cuántos ítems recientes de cada fuente entran en la bandeja unificada. */
    public const WINDOW = 300;

    /** Destinatarios por trabajo encolado (acota el tamaño del payload y de la consulta agrupada). */
    private const NOTIFY_CHUNK = 500;

    /** Ventana (segundos) en la que varios cambios de la bandeja de la oficina se avisan una sola vez. */
    private const OFFICE_DEBOUNCE_SECONDS = 5;

    /**
     * ¿Hay transporte de tiempo real configurado? Con `null` (default seguro) no hay a quién emitir.
     */
    public static function realtimeEnabled(): bool
    {
        $driver = config('broadcasting.default');

        return is_string($driver) && $driver !== '' && $driver !== 'null';
    }

    /**
     * Avisa (por la cola) que cambió el no-leído de estas personas. Nunca lanza: si encolar falla, la bandeja
     * ya quedó bien y la app la verá en su próximo refresco.
     *
     * @param list<int> $userIds
     */
    public function changed(array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));

        foreach (array_chunk($userIds, self::NOTIFY_CHUNK) as $chunk) {
            try {
                BroadcastInboxUpdated::dispatch($chunk);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Avisa (por la cola) a toda la oficina que cambió su bandeja: una alerta de flota nueva o reconocida,
     * un documento de flota cargado o renovado. Punto de enganche para esas fuentes; nunca lanza.
     */
    public function officeChanged(): void
    {
        try {
            // Una ráfaga de alertas (p. ej. exceso de velocidad sostenido) no encola un trabajo por cada una:
            // a lo sumo uno por ventana, demorado lo mismo, para que el número que llegue ya incluya la ráfaga.
            if (!Cache::add('staff-inbox:office-changed', true, self::OFFICE_DEBOUNCE_SECONDS)) {
                return;
            }

            BroadcastInboxUpdated::dispatch([], true)->delay(self::OFFICE_DEBOUNCE_SECONDS);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * No-leído de cada persona, con UNA consulta agrupada (más los pendientes operativos, una sola vez, si
     * entre ellas hay gente de oficina). Devuelve una entrada por cada id pedido, en 0 si no tiene nada.
     *
     * @param  list<int>       $userIds
     * @return array<int, int>
     */
    public function unreadFor(array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return [];
        }

        $counts = array_fill_keys($userIds, 0);

        $rows = DB::table('staff_broadcast_recipients')
            ->whereIn('user_id', $userIds)
            ->whereNull('read_at')
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) as n')
            ->get();
        foreach ($rows as $row) {
            $counts[(int) $row->user_id] = (int) $row->n;
        }

        $office = DB::table('user_roles')
            ->whereIn('user_id', $userIds)
            ->whereIn('role', self::officeRoles())
            ->distinct()
            ->pluck('user_id')
            ->all();
        if ($office !== []) {
            $operational = count($this->operationalItems());
            foreach ($office as $userId) {
                if (is_numeric($userId) && isset($counts[(int) $userId])) {
                    $counts[(int) $userId] += $operational;
                }
            }
        }

        return $counts;
    }

    /**
     * No-leído de una sola persona (el `meta.unread` de la bandeja).
     */
    public function unread(int $userId): int
    {
        return $this->unreadFor([$userId])[$userId] ?? 0;
    }

    /**
     * Ids de la gente de oficina (admin y supervisor): quienes ven alertas y documentos en su bandeja.
     *
     * @return list<int>
     */
    public function officeUserIds(): array
    {
        $ids = [];
        foreach (DB::table('user_roles')->whereIn('role', self::officeRoles())->distinct()->pluck('user_id') as $userId) {
            if (is_numeric($userId)) {
                $ids[] = (int) $userId;
            }
        }

        return $ids;
    }

    /**
     * Pendientes operativos de la oficina, con la misma forma que un aviso: alertas de flota sin reconocer y
     * documentos de flota por vencer / vencidos. Se piden por el QueryBus; cada fuente es tolerante.
     *
     * @return list<array<string, mixed>>
     */
    public function operationalItems(): array
    {
        $items = [];

        try {
            /** @var list<AlertView> $alerts */
            $alerts = app(QueryBus::class)->ask(new ListAlerts(acknowledged: false));
            foreach (array_slice($alerts, 0, self::WINDOW) as $alert) {
                $items[] = [
                    'kind' => 'alert',
                    'id' => $alert->id,
                    'title' => $alert->type,
                    'body' => $alert->vehicleId,
                    'link' => null,
                    'sent_at' => $this->iso($alert->occurredAt),
                    'read' => false,
                    'read_at' => null,
                    'severity' => $alert->severity,
                ];
            }
        } catch (Throwable) {
            // telemetría no disponible: la bandeja sigue con el resto.
        }

        try {
            $days = app(DocumentExpiryPolicy::class)->alertWindowDays();
            /** @var list<ExpiringDocumentView> $documents */
            $documents = app(QueryBus::class)->ask(new ListExpiringDocuments($days));
            foreach (array_slice($documents, 0, self::WINDOW) as $document) {
                $items[] = [
                    'kind' => 'document',
                    'id' => $document->ownerType.':'.$document->ownerId.':'.$document->documentType,
                    'title' => $document->documentType,
                    'body' => $document->ownerLabel,
                    'link' => null,
                    'sent_at' => $this->iso($document->expiresOn),
                    'read' => false,
                    'read_at' => null,
                    'severity' => $document->expired ? 'expired' : 'expiring',
                ];
            }
        } catch (Throwable) {
            // flota no disponible: la bandeja sigue con el resto.
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private static function officeRoles(): array
    {
        return [Role::Admin->value, Role::Supervisor->value];
    }

    private function iso(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toAtomString();
        } catch (Throwable) {
            return null;
        }
    }
}
