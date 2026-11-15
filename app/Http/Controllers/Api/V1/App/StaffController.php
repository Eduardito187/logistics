<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Enums\Role;
use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Http\Support\ApiPagination;
use App\Support\Staff\StaffCity;
use App\Support\Staff\StaffDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * Ficha del PERSONAL para la app (solo admin y supervisor): una sola vista para cualquier rol.
 *
 *  - GET  /app/staff                       personal paginado (búsqueda, rol, tienda, ciudad)
 *  - GET  /app/staff/{userId}              ficha: cuenta, roles, tienda, datos de chofer, vehículo, dispositivos
 *  - GET  /app/staff/{userId}/documents    documentos (paginado)
 *  - POST /app/staff/{userId}/documents    sube un documento (multipart)
 *  - GET  /app/staff/{userId}/activity     actividad según su rol (paginado, filtro por tipo y fechas)
 *  - GET  /app/staff/{userId}/photos       fotos (perfil, documentos, evidencia que capturó)
 *  - PUT  /app/staff/{userId}/node         asigna (o quita) la tienda / nodo de la persona
 *  - PUT  /app/staff/{userId}/profile      guarda sus datos personales (teléfono, documento, dirección…)
 *  - POST /app/staff/{userId}/photo        sube su foto de perfil
 *
 * El personal de la app vive en `users` (id entero); las lecturas las arma {@see StaffDirectory}. Asignar
 * un vehículo a un chofer se hace con las tripulaciones (POST /app/fleet/crews), no acá.
 */
final class StaffController
{
    private const ACTIVITY_TYPES = ['scan', 'assignment', 'checklist', 'device'];

    public function __construct(
        private readonly StaffDirectory $staff,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var array{q?: string|null, role?: string|null, node_id?: string|null, without_node?: bool|string|int|null, city?: string|null, without_city?: bool|string|int|null} $filters */
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'role' => ['sometimes', 'nullable', 'string', Rule::in(array_map(static fn (Role $r): string => $r->value, Role::cases()))],
            'node_id' => ['sometimes', 'nullable', 'uuid'],
            // true = solo personal SIN tienda asignada (para repartirlo).
            'without_node' => ['sometimes', 'nullable', 'in:true,false,1,0'],
            // Ciudad (código de departamento) o, con without_city=true, solo personal SIN ciudad asignada.
            'city' => ['sometimes', 'nullable', 'string', Rule::in(StaffCity::codes())],
            'without_city' => ['sometimes', 'nullable', 'in:true,false,1,0'],
        ]);

        $page = $this->staff->list(
            $filters['q'] ?? null,
            $filters['role'] ?? null,
            $filters['node_id'] ?? null,
            filter_var($filters['without_node'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ApiPagination::limit($request),
            ApiPagination::offset($request),
            $filters['city'] ?? null,
            filter_var($filters['without_city'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );

        return new JsonResponse(ApiPagination::envelope($page['items'], $page['total'], $request));
    }

    public function show(Request $request, int $userId): JsonResponse
    {
        $profile = $this->staff->profile($userId);

        return $profile === null ? $this->notFound($request) : new JsonResponse(['data' => $profile]);
    }

    public function documents(Request $request, int $userId): JsonResponse
    {
        if (!$this->exists($userId)) {
            return $this->notFound($request);
        }

        $page = $this->staff->documents($userId, ApiPagination::limit($request), ApiPagination::offset($request));

        return new JsonResponse(ApiPagination::envelope($page['items'], $page['total'], $request));
    }

    public function storeDocument(Request $request, int $userId): JsonResponse
    {
        if (!$this->exists($userId)) {
            return $this->notFound($request);
        }

        /** @var array{document_type: string, reference?: string|null, issued_on?: string|null, expires_on?: string|null, note?: string|null} $data */
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:60'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])->max(10 * 1024)],
        ]);

        $file = $request->file('file');
        if (!$file instanceof UploadedFile) {
            return ProblemDetails::fromCode(ErrorCode::ValidationFailed, 'The file is required.')->toResponse($request);
        }

        $path = $file->store('staff/'.$userId, ['disk' => StaffDirectory::DOCUMENTS_DISK]);
        if (!is_string($path) || $path === '') {
            return ProblemDetails::fromCode(ErrorCode::BusinessRuleViolation, 'The document could not be stored.')->toResponse($request);
        }

        $id = (string) Str::uuid7();
        DB::table('staff_documents')->insert([
            'id' => $id,
            'user_id' => $userId,
            'document_type' => trim($data['document_type']),
            'reference' => $data['reference'] ?? null,
            'issued_on' => $data['issued_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'file_disk' => StaffDirectory::DOCUMENTS_DISK,
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'note' => $data['note'] ?? null,
            'uploaded_by' => is_numeric($request->user()?->getAuthIdentifier()) ? (int) $request->user()->getAuthIdentifier() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = null;
        foreach ($this->staff->documents($userId, 500, 0)['items'] as $doc) {
            if (($doc['id'] ?? null) === $id) {
                $created = $doc;
                break;
            }
        }

        return new JsonResponse(['data' => $created ?? ['id' => $id]], 201);
    }

    public function activity(Request $request, int $userId): JsonResponse
    {
        if (!$this->exists($userId)) {
            return $this->notFound($request);
        }

        /** @var array{type?: string|null, from?: string|null, to?: string|null} $filters */
        $filters = $request->validate([
            'type' => ['sometimes', 'nullable', 'string', Rule::in(self::ACTIVITY_TYPES)],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $page = $this->staff->activity(
            $userId,
            $filters['type'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            ApiPagination::limit($request),
            ApiPagination::offset($request),
        );

        return new JsonResponse(ApiPagination::envelope($page['items'], $page['total'], $request));
    }

    public function photos(Request $request, int $userId): JsonResponse
    {
        if (!$this->exists($userId)) {
            return $this->notFound($request);
        }

        $page = $this->staff->photos($userId, ApiPagination::limit($request), ApiPagination::offset($request));

        return new JsonResponse(ApiPagination::envelope($page['items'], $page['total'], $request));
    }

    /**
     * Asigna la persona a una tienda / nodo, o la deja sin tienda (`node_id: null`). Para el rol tienda es lo
     * que acota qué pedidos ve y entrega; un cambio corre desde su próximo request.
     */
    public function assignNode(Request $request, int $userId): JsonResponse
    {
        if (!$this->exists($userId)) {
            return $this->notFound($request);
        }

        /** @var array{node_id: string|null} $data */
        $data = $request->validate([
            'node_id' => ['present', 'nullable', 'uuid', Rule::exists('network_nodes', 'id')],
        ]);

        $changes = ['node_id' => $data['node_id'], 'updated_at' => now()];

        // Regla de consistencia: quien pertenece a un nodo es de la ciudad (departamento) de ese nodo. Al
        // quitar el nodo la ciudad se conserva: la persona sigue siendo de donde era.
        $nodeCity = StaffCity::ofNode($data['node_id']);
        if ($nodeCity !== null) {
            $changes['city'] = $nodeCity;
        }

        DB::table('users')->where('id', $userId)->update($changes);

        return new JsonResponse(['data' => $this->staff->profile($userId)]);
    }

    /**
     * Guarda los datos personales de la persona, sea del rol que sea. Solo cambia lo que viene en el cuerpo
     * (un campo en null lo borra); lo demás queda como estaba. También acepta `city` (ciudad de la persona):
     * si pertenece a un nodo, solo se admite la ciudad de ese nodo (422 con el motivo si no coincide).
     */
    public function updateProfile(Request $request, int $userId): JsonResponse
    {
        if (!$this->exists($userId)) {
            return $this->notFound($request);
        }

        /** @var array<string, string|null> $data */
        $data = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'phone_secondary' => ['sometimes', 'nullable', 'string', 'max:40'],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Acepta la fecha sola o una fecha-hora ISO (los clientes generados serializan así un `date`).
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Ciudad (departamento): código del catálogo (GET /app/network/cities) o null para quitarla.
            'city' => ['sometimes', 'nullable', 'string', Rule::in(StaffCity::codes())],
        ]);

        // La ciudad vive en `users`, no en el perfil: se separa del resto y se valida contra el nodo.
        if (array_key_exists('city', $data)) {
            $city = $data['city'];
            unset($data['city']);

            $nodeId = DB::table('users')->where('id', $userId)->value('node_id');
            $nodeCity = StaffCity::ofNode(is_string($nodeId) ? $nodeId : null);
            if ($nodeCity !== null && $city !== $nodeCity) {
                return ProblemDetails::fromCode(
                    ErrorCode::ValidationFailed,
                    sprintf(
                        'This person belongs to a store or node in %s, so their city must be %s. Change or remove the node first.',
                        (string) StaffCity::name($nodeCity),
                        (string) StaffCity::name($nodeCity),
                    ),
                )->toResponse($request);
            }

            DB::table('users')->where('id', $userId)->update(['city' => $city, 'updated_at' => now()]);
        }

        // Se guarda el día tal como se escribió (sin convertir zona horaria): de una fecha-hora, su parte de fecha.
        if (is_string($data['birth_date'] ?? null)) {
            $data['birth_date'] = preg_match('/^\d{4}-\d{2}-\d{2}/', $data['birth_date'], $day) === 1
                ? $day[0]
                : date('Y-m-d', (int) strtotime($data['birth_date']));
        }

        $this->upsertProfile($userId, $data);

        return new JsonResponse(['data' => $this->staff->profile($userId)]);
    }

    /**
     * Sube la foto de perfil de la persona (reemplaza la anterior).
     */
    public function uploadPhoto(Request $request, int $userId): JsonResponse
    {
        if (!$this->exists($userId)) {
            return $this->notFound($request);
        }

        $request->validate([
            'photo' => ['required', File::image()->max(8 * 1024)],
        ]);

        $photo = $request->file('photo');
        if (!$photo instanceof UploadedFile) {
            return ProblemDetails::fromCode(ErrorCode::ValidationFailed, 'The photo is required.')->toResponse($request);
        }

        $path = $photo->store('staff/'.$userId.'/profile', ['disk' => StaffDirectory::DOCUMENTS_DISK]);
        if (!is_string($path) || $path === '') {
            return ProblemDetails::fromCode(ErrorCode::BusinessRuleViolation, 'The photo could not be stored.')->toResponse($request);
        }

        $previous = DB::table('staff_profiles')->where('user_id', $userId)->first(['photo_disk', 'photo_path']);
        $this->upsertProfile($userId, ['photo_disk' => StaffDirectory::DOCUMENTS_DISK, 'photo_path' => $path]);

        // La foto anterior ya no se usa: se borra del disco (best-effort).
        if ($previous !== null && is_string($previous->photo_path ?? null) && $previous->photo_path !== '' && $previous->photo_path !== $path) {
            try {
                Storage::disk((string) ($previous->photo_disk ?? StaffDirectory::DOCUMENTS_DISK))->delete($previous->photo_path);
            } catch (\Throwable) {
                // el archivo viejo queda huérfano; no afecta a la ficha.
            }
        }

        return new JsonResponse(['data' => $this->staff->profile($userId)], 201);
    }

    /**
     * @param array<string, string|null> $values
     */
    private function upsertProfile(int $userId, array $values): void
    {
        if ($values === []) {
            return;
        }

        $now = now();
        if (DB::table('staff_profiles')->where('user_id', $userId)->exists()) {
            DB::table('staff_profiles')->where('user_id', $userId)->update($values + ['updated_at' => $now]);

            return;
        }

        DB::table('staff_profiles')->insert($values + ['user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function exists(int $userId): bool
    {
        return DB::table('users')->where('id', $userId)->exists();
    }

    private function notFound(Request $request): JsonResponse
    {
        return ProblemDetails::fromCode(ErrorCode::NotFound, 'Staff member not found.')->toResponse($request);
    }
}
