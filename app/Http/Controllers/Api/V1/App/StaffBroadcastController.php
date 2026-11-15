<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Enums\Role;
use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Http\Support\ApiPagination;
use App\Jobs\SendStaffBroadcastPush;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Domain\Contract\DocumentExpiryPolicy;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\ListAlerts\ListAlerts;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use stdClass;
use Throwable;

/**
 * Avisos INTERNOS al personal de la app (no al cliente).
 *
 *  - POST /app/broadcasts                 envía un aviso a un rol, a una tienda o a personas puntuales (solo admin)
 *  - GET  /app/broadcasts                 avisos enviados, con cuántos lo recibieron y lo leyeron (admin / supervisor)
 *  - GET  /app/inbox                      bandeja ÚNICA del usuario (cualquier rol): avisos internos y, para
 *                                         la oficina, alertas de flota y documentos por vencer
 *  - POST /app/inbox/{broadcastId}/read   marca uno como leído
 *  - POST /app/inbox/read-all             marca todos como leídos
 *
 * El aviso queda en la bandeja de cada destinatario en el momento; el push sale después por la cola (ver
 * {@see SendStaffBroadcastPush}). La bandeja `/app/notifications` existente es otra cosa: los avisos al
 * CLIENTE por pedido.
 */
final class StaffBroadcastController
{
    /** Tope de destinatarios por aviso (protege de un envío masivo por error). */
    private const MAX_RECIPIENTS = 5000;

    /** Cuántos ítems recientes de cada fuente entran en la bandeja unificada. */
    private const INBOX_WINDOW = 300;

    public function send(Request $request): JsonResponse
    {
        /** @var array{audience: array{role?: string|null, node_id?: string|null, user_ids?: list<int>}, title: string, body: string, link?: string|null} $data */
        $data = $request->validate([
            'audience' => ['required', 'array'],
            'audience.role' => ['sometimes', 'nullable', 'string', Rule::in(array_map(static fn (Role $r): string => $r->value, Role::cases()))],
            'audience.node_id' => ['sometimes', 'nullable', 'uuid'],
            'audience.user_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'audience.user_ids.*' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            'link' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        // Solo las claves conocidas del alcance (un alcance con claves ajenas queda vacío y se rechaza).
        $audience = array_intersect_key((array) ($data['audience'] ?? []), ['role' => true, 'node_id' => true, 'user_ids' => true]);
        $recipients = $this->recipients($audience);
        if ($recipients === null) {
            return ProblemDetails::fromCode(ErrorCode::ValidationFailed, 'Send at least one audience: role, node_id or user_ids.')->toResponse($request);
        }
        if ($recipients === []) {
            return ProblemDetails::fromCode(ErrorCode::BusinessRuleViolation, 'Nobody matches that audience.')->toResponse($request);
        }
        if (count($recipients) > self::MAX_RECIPIENTS) {
            return ProblemDetails::fromCode(ErrorCode::BusinessRuleViolation, 'That audience is too large for a single notice.')->toResponse($request);
        }

        $id = (string) Str::uuid7();
        $senderId = $request->user()?->getAuthIdentifier();
        $now = now();

        DB::transaction(static function () use ($id, $data, $audience, $recipients, $senderId, $now): void {
            DB::table('staff_broadcasts')->insert([
                'id' => $id,
                'title' => trim($data['title']),
                'body' => trim($data['body']),
                'link' => isset($data['link']) && trim((string) $data['link']) !== '' ? trim((string) $data['link']) : null,
                'audience' => json_encode($audience, JSON_THROW_ON_ERROR),
                'sent_by' => is_numeric($senderId) ? (int) $senderId : null,
                'recipient_count' => count($recipients),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (array_chunk($recipients, 500) as $chunk) {
                DB::table('staff_broadcast_recipients')->insert(array_map(static fn (int $userId): array => [
                    'id' => (string) Str::uuid7(),
                    'broadcast_id' => $id,
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        // El push va por la cola; si encolar falla, el aviso igual quedó en las bandejas.
        try {
            SendStaffBroadcastPush::dispatch($id);
        } catch (Throwable $e) {
            report($e);
        }

        return new JsonResponse(['data' => ['id' => $id, 'recipient_count' => count($recipients)]], 201);
    }

    public function sent(Request $request): JsonResponse
    {
        $query = DB::table('staff_broadcasts');
        $total = (clone $query)->count();

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')
            ->limit(ApiPagination::limit($request))->offset(ApiPagination::offset($request))
            ->get()->all();

        $ids = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $read = [];
        if ($ids !== []) {
            foreach (DB::table('staff_broadcast_recipients')->whereIn('broadcast_id', $ids)->whereNotNull('read_at')
                ->groupBy('broadcast_id')->selectRaw('broadcast_id, count(*) as n')->get() as $row) {
                $read[(string) $row->broadcast_id] = (int) $row->n;
            }
        }

        $senderIds = array_values(array_unique(array_filter(array_map(static fn (stdClass $row): mixed => $row->sent_by, $rows))));
        $senders = $senderIds !== [] ? DB::table('users')->whereIn('id', $senderIds)->pluck('name', 'id')->all() : [];

        $items = array_values(array_map(fn (stdClass $row): array => $this->present($row) + [
            'audience' => json_decode((string) $row->audience, true),
            'recipient_count' => (int) $row->recipient_count,
            'read_count' => $read[(string) $row->id] ?? 0,
            'sent_by' => $row->sent_by !== null ? ['id' => (int) $row->sent_by, 'name' => $senders[$row->sent_by] ?? null] : null,
        ], $rows));

        return new JsonResponse(ApiPagination::envelope($items, $total, $request));
    }

    /**
     * Bandeja ÚNICA del usuario: todo lo que va dirigido a él, de lo más reciente a lo más viejo.
     *  - `notice`: avisos internos que le enviaron (se marcan como leídos);
     *  - `alert` y `document` (solo admin y supervisor): alertas de la flota sin reconocer y documentos por
     *    vencer o vencidos. No se "leen": desaparecen cuando se resuelven, y cuentan como pendientes.
     * `meta.unread` es el total pendiente para el badge. `?unread=true` deja solo lo pendiente.
     */
    public function inbox(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        /** @var array{unread?: string|null} $filters */
        $filters = $request->validate(['unread' => ['sometimes', 'nullable', 'in:true,false,1,0']]);
        $onlyUnread = filter_var($filters['unread'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $query = DB::table('staff_broadcast_recipients as r')
            ->join('staff_broadcasts as b', 'b.id', '=', 'r.broadcast_id')
            ->where('r.user_id', $userId);
        if ($onlyUnread) {
            $query->whereNull('r.read_at');
        }

        $rows = $query->orderByDesc('b.created_at')->orderByDesc('b.id')
            ->limit(self::INBOX_WINDOW)
            ->get(['b.id', 'b.title', 'b.body', 'b.link', 'b.created_at', 'r.read_at'])->all();

        $items = array_values(array_map(fn (stdClass $row): array => ['kind' => 'notice'] + $this->present($row) + [
            'read' => $row->read_at !== null,
            'read_at' => $this->iso($row->read_at),
            'severity' => null,
        ], $rows));

        $operational = $this->isOffice($request) ? $this->operationalItems() : [];
        $items = [...$items, ...$operational];
        usort($items, static fn (array $x, array $y): int => strcmp((string) ($y['sent_at'] ?? ''), (string) ($x['sent_at'] ?? '')));

        $unread = DB::table('staff_broadcast_recipients')->where('user_id', $userId)->whereNull('read_at')->count() + count($operational);

        $envelope = ApiPagination::paginate($items, $request);
        $envelope['meta']['unread'] = $unread;

        return new JsonResponse($envelope);
    }

    /**
     * Pendientes operativos de la oficina, con la misma forma que un aviso: alertas de flota sin reconocer y
     * documentos de flota por vencer / vencidos. Se piden por el QueryBus; cada fuente es tolerante.
     *
     * @return list<array<string, mixed>>
     */
    private function operationalItems(): array
    {
        $items = [];

        try {
            /** @var list<AlertView> $alerts */
            $alerts = app(QueryBus::class)->ask(new ListAlerts(acknowledged: false));
            foreach (array_slice($alerts, 0, self::INBOX_WINDOW) as $alert) {
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
            foreach (array_slice($documents, 0, self::INBOX_WINDOW) as $document) {
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

    private function isOffice(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor);
    }

    public function read(Request $request, string $broadcastId): JsonResponse
    {
        $updated = DB::table('staff_broadcast_recipients')
            ->where('broadcast_id', $broadcastId)
            ->where('user_id', $this->userId($request))
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        $exists = $updated > 0 || DB::table('staff_broadcast_recipients')
            ->where('broadcast_id', $broadcastId)->where('user_id', $this->userId($request))->exists();

        return $exists
            ? new JsonResponse(['data' => ['id' => $broadcastId, 'read' => true]])
            : ProblemDetails::fromCode(ErrorCode::NotFound, 'Notice not found.')->toResponse($request);
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = DB::table('staff_broadcast_recipients')
            ->where('user_id', $this->userId($request))
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return new JsonResponse(['data' => ['marked' => $updated]]);
    }

    /**
     * Ids de usuario que cumplen el alcance (rol Y tienda si vienen los dos; `user_ids` se suma). Null si no
     * vino ningún criterio.
     *
     * @param  array{role?: string|null, node_id?: string|null, user_ids?: list<int>} $audience
     * @return list<int>|null
     */
    private function recipients(array $audience): ?array
    {
        $role = isset($audience['role']) && $audience['role'] !== '' ? (string) $audience['role'] : null;
        $nodeId = isset($audience['node_id']) && $audience['node_id'] !== '' ? (string) $audience['node_id'] : null;
        $userIds = array_values(array_unique(array_map('intval', $audience['user_ids'] ?? [])));

        if ($role === null && $nodeId === null && $userIds === []) {
            return null;
        }

        $ids = [];
        if ($role !== null || $nodeId !== null) {
            $query = DB::table('users');
            if ($role !== null) {
                $query->whereIn('id', static function ($roles) use ($role): void {
                    $roles->select('user_id')->from('user_roles')->where('role', $role);
                });
            }
            if ($nodeId !== null) {
                $query->where('node_id', $nodeId);
            }
            foreach ($query->limit(self::MAX_RECIPIENTS + 1)->pluck('id') as $id) {
                $ids[(int) $id] = true;
            }
        }

        if ($userIds !== []) {
            foreach (DB::table('users')->whereIn('id', $userIds)->pluck('id') as $id) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return array{id: string, title: string, body: string, link: string|null, sent_at: string|null}
     */
    private function present(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'body' => (string) $row->body,
            'link' => is_string($row->link ?? null) && $row->link !== '' ? $row->link : null,
            'sent_at' => $this->iso($row->created_at ?? null),
        ];
    }

    private function userId(Request $request): int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : 0;
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
