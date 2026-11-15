<?php

declare(strict_types=1);

namespace App\Support\Staff;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Presentation\Support\EvidenceFileUrl;
use Logistics\Shared\Presentation\Filament\ImageFallback;
use stdClass;
use Throwable;

/**
 * Lado de LECTURA de la ficha del personal para la app (rol admin / supervisor): una sola vista para
 * cualquier rol. Compone, en primitivos, lo que hoy está repartido entre varias tablas: la cuenta y sus
 * roles (`users` + `user_roles`), su tienda (`network_nodes`), sus datos de chofer si los tiene
 * (`fleet_drivers`), el vehículo vigente (`fleet_crews`), sus dispositivos, documentos, actividad y fotos.
 *
 * Solo lectura y tolerante: cada bloque opcional degrada a vacío si su fuente no está disponible. El
 * personal de la app vive en `users`; el módulo de acceso (`access_users`) es el de cuentas administrativas.
 */
final class StaffDirectory
{
    /** Disco privado donde se guardan los documentos subidos desde la ficha (datos personales). */
    public const DOCUMENTS_DISK = 'id_document';

    /**
     * Personal paginado, con búsqueda por nombre / email y filtros por rol, por tienda y por ciudad
     * (`$city` = código del departamento; `$withoutCity` = solo quienes no tienen ciudad asignada).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(
        ?string $search,
        ?string $role,
        ?string $nodeId,
        bool $withoutNode,
        int $limit,
        int $offset,
        ?string $city = null,
        bool $withoutCity = false,
    ): array {
        $query = DB::table('users');

        $needle = $search !== null ? mb_strtolower(trim($search)) : '';
        if ($needle !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';
            $query->where(static function (Builder $where) use ($like): void {
                $where->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]);
            });
        }
        if ($role !== null && $role !== '') {
            $query->whereIn('id', static function (Builder $roles) use ($role): void {
                $roles->select('user_id')->from('user_roles')->where('role', $role);
            });
        }
        if ($nodeId !== null && $nodeId !== '') {
            $query->where('node_id', $nodeId);
        } elseif ($withoutNode) {
            $query->whereNull('node_id');
        }
        if ($city !== null && $city !== '') {
            $query->where('city', $city);
        } elseif ($withoutCity) {
            $query->whereNull('city');
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('name')->orderBy('id')
            ->limit(max(1, $limit))->offset(max(0, $offset))
            ->get(['id', 'name', 'email', 'node_id', 'driver_id', 'carrier_id', 'city'])
            ->all();

        return ['items' => $this->summaries($rows), 'total' => $total];
    }

    /**
     * Ficha completa de una persona, o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function profile(int $userId): ?array
    {
        $row = DB::table('users')->where('id', $userId)->first(['id', 'name', 'email', 'node_id', 'driver_id', 'carrier_id', 'city', 'created_at']);
        if (!$row instanceof stdClass) {
            return null;
        }

        $summary = $this->summaries([$row])[0];
        $driver = $this->driver(is_string($row->driver_id) ? $row->driver_id : null);

        return $summary + [
            'created_at' => $this->iso($row->created_at ?? null),
            'personal' => $this->personal($userId, $driver),
            'driver' => $driver,
            'current_vehicle' => $this->currentVehicle(is_string($row->driver_id) ? $row->driver_id : null),
            'devices' => $this->devices($userId),
        ];
    }

    /**
     * Documentos de la persona: los subidos a su ficha y, si es chofer, los de su ficha de flota.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function documents(int $userId, int $limit, int $offset): array
    {
        $items = [];

        try {
            foreach (DB::table('staff_documents')->where('user_id', $userId)->orderByDesc('created_at')->get() as $doc) {
                $items[] = [
                    'id' => (string) $doc->id,
                    'source' => 'staff',
                    'document_type' => (string) $doc->document_type,
                    'reference' => $this->str($doc->reference ?? null),
                    'issued_on' => $this->date($doc->issued_on ?? null),
                    'expires_on' => $this->date($doc->expires_on ?? null),
                    'expired' => $this->isExpired($doc->expires_on ?? null),
                    'note' => $this->str($doc->note ?? null),
                    'mime_type' => $this->str($doc->mime_type ?? null),
                    'url' => $this->fileUrl((string) $doc->file_disk, (string) $doc->file_path),
                    'created_at' => $this->iso($doc->created_at ?? null),
                ];
            }
        } catch (Throwable) {
            // tabla ausente en este entorno: se listan solo los de flota.
        }

        $driverId = DB::table('users')->where('id', $userId)->value('driver_id');
        if (is_string($driverId) && $driverId !== '') {
            try {
                $fleetDisk = (string) config('fleet.documents_disk', 's3');
                foreach (DB::table('fleet_documents')->where('owner_type', 'driver')->where('owner_id', $driverId)->orderByDesc('created_at')->get() as $doc) {
                    $path = $this->str($doc->file_path ?? null);
                    $items[] = [
                        'id' => (string) $doc->id,
                        'source' => 'fleet',
                        'document_type' => (string) $doc->document_type,
                        'reference' => $this->str($doc->reference ?? null),
                        'issued_on' => $this->date($doc->issued_on ?? null),
                        'expires_on' => $this->date($doc->expires_on ?? null),
                        'expired' => $this->isExpired($doc->expires_on ?? null),
                        'note' => $this->str($doc->note ?? null),
                        'mime_type' => null,
                        'url' => $path !== null ? $this->fileUrl($fleetDisk, $path) : null,
                        'created_at' => $this->iso($doc->created_at ?? null),
                    ];
                }
            } catch (Throwable) {
                // sin documentos de flota disponibles.
            }
        }

        return ['items' => array_slice($items, max(0, $offset), max(1, $limit)), 'total' => count($items)];
    }

    /**
     * Actividad de la persona, de lo más reciente a lo más viejo. `type` acota a una fuente:
     * `scan` (escaneos en terreno), `assignment` (vehículo asignado), `checklist` (inspección pre-salida),
     * `device` (último uso de un dispositivo). Rango opcional por fecha (Y-m-d, inclusive).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function activity(int $userId, ?string $type, ?string $from, ?string $to, int $limit, int $offset): array
    {
        $window = max(1, $offset + $limit);
        $driverId = DB::table('users')->where('id', $userId)->value('driver_id');
        $driverId = is_string($driverId) && $driverId !== '' ? $driverId : null;

        $items = [];
        $total = 0;
        $sources = [
            'scan' => fn (): array => $this->scans($userId, $from, $to, $window),
            'assignment' => fn (): array => $driverId !== null ? $this->assignments($driverId, $from, $to, $window) : [[], 0],
            'checklist' => fn (): array => $driverId !== null ? $this->checklists($driverId, $from, $to, $window) : [[], 0],
            'device' => fn (): array => $this->deviceActivity($userId, $from, $to, $window),
        ];

        foreach ($sources as $name => $read) {
            if ($type !== null && $type !== '' && $type !== $name) {
                continue;
            }
            try {
                [$rows, $count] = $read();
                $items = [...$items, ...$rows];
                $total += $count;
            } catch (Throwable) {
                // fuente no disponible: se omite.
            }
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return ['items' => array_slice($items, max(0, $offset), max(1, $limit)), 'total' => $total];
    }

    /**
     * Fotos de la persona: su foto de perfil, las imágenes de sus documentos y la evidencia que capturó
     * (firmas / fotos de entrega), de lo más reciente a lo más viejo.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function photos(int $userId, int $limit, int $offset): array
    {
        $items = [];

        $profile = $this->profilePhoto($userId);
        if ($profile !== null) {
            $items[] = ['kind' => 'profile', 'url' => $profile, 'at' => null, 'label' => null];
        }

        foreach ($this->documents($userId, 500, 0)['items'] as $doc) {
            $mime = is_string($doc['mime_type'] ?? null) ? $doc['mime_type'] : '';
            if (is_string($doc['url'] ?? null) && ($mime === '' || str_starts_with($mime, 'image/'))) {
                $items[] = ['kind' => 'document', 'url' => $doc['url'], 'at' => $doc['created_at'], 'label' => $doc['document_type']];
            }
        }

        try {
            $evidence = DB::table('execution_delivery_evidence')
                ->where('captured_by_user', (string) $userId)
                ->orderByDesc('captured_at')
                ->limit(200)
                ->get(['kind', 'storage_disk', 'storage_path', 'captured_at']);

            foreach ($evidence as $row) {
                $url = EvidenceFileUrl::forDisk((string) $row->storage_disk, (string) $row->storage_path);
                if ($url !== null) {
                    $items[] = ['kind' => 'evidence', 'url' => $url, 'at' => $this->iso($row->captured_at ?? null), 'label' => (string) $row->kind];
                }
            }
        } catch (Throwable) {
            // sin evidencia disponible.
        }

        return ['items' => array_slice($items, max(0, $offset), max(1, $limit)), 'total' => count($items)];
    }

    /**
     * Resumen de cada usuario (roles, tienda, ciudad, teléfono y foto si tiene ficha de chofer), en lote.
     *
     * @param  array<int, mixed>          $rows
     * @return list<array<string, mixed>>
     */
    private function summaries(array $rows): array
    {
        $userIds = [];
        $nodeIds = [];
        $driverIds = [];
        foreach ($rows as $row) {
            if (!$row instanceof stdClass) {
                continue;
            }
            $userIds[] = (int) $row->id;
            if (is_string($row->node_id ?? null) && $row->node_id !== '') {
                $nodeIds[] = $row->node_id;
            }
            if (is_string($row->driver_id ?? null) && $row->driver_id !== '') {
                $driverIds[] = $row->driver_id;
            }
        }

        $roles = [];
        foreach (DB::table('user_roles')->whereIn('user_id', $userIds)->orderBy('role')->get(['user_id', 'role']) as $role) {
            $roles[(int) $role->user_id][] = (string) $role->role;
        }

        $nodes = [];
        if ($nodeIds !== []) {
            foreach (DB::table('network_nodes')->whereIn('id', array_unique($nodeIds))->get(['id', 'name', 'type', 'department']) as $node) {
                $nodes[(string) $node->id] = [
                    'id' => (string) $node->id,
                    'name' => $this->str($node->name ?? null),
                    'type' => $this->str($node->type ?? null),
                    'city' => $this->str($node->department ?? null),
                ];
            }
        }

        $drivers = [];
        if ($driverIds !== []) {
            try {
                foreach (DB::table('fleet_drivers')->whereIn('id', array_unique($driverIds))->get(['id', 'phone_primary', 'national_id', 'photo_path', 'active']) as $driver) {
                    $drivers[(string) $driver->id] = $driver;
                }
            } catch (Throwable) {
                // ficha de chofer no disponible.
            }
        }

        // Datos personales propios del usuario (cualquier rol). Mandan sobre los de la ficha de chofer.
        $profiles = [];
        if ($userIds !== []) {
            try {
                foreach (DB::table('staff_profiles')->whereIn('user_id', $userIds)->get() as $profile) {
                    $profiles[(int) $profile->user_id] = $profile;
                }
            } catch (Throwable) {
                // tabla ausente en este entorno.
            }
        }

        $out = [];
        foreach ($rows as $row) {
            if (!$row instanceof stdClass) {
                continue;
            }
            $driver = is_string($row->driver_id ?? null) ? ($drivers[$row->driver_id] ?? null) : null;
            $profile = $profiles[(int) $row->id] ?? null;
            $photoPath = $driver !== null ? $this->str($driver->photo_path ?? null) : null;
            $ownPhoto = $profile !== null ? $this->str($profile->photo_path ?? null) : null;

            $out[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'roles' => $roles[(int) $row->id] ?? [],
                'node' => is_string($row->node_id ?? null) ? ($nodes[$row->node_id] ?? null) : null,
                // Ciudad (departamento) de la persona: código del catálogo y nombre legible, o null.
                'city' => StaffCity::code($this->str($row->city ?? null)),
                'city_name' => StaffCity::name($this->str($row->city ?? null)),
                'driver_id' => $this->str($row->driver_id ?? null),
                'carrier_id' => $this->str($row->carrier_id ?? null),
                'phone' => $this->str($profile->phone ?? null) ?? ($driver !== null ? $this->str($driver->phone_primary ?? null) : null),
                'national_id' => $this->str($profile->national_id ?? null) ?? ($driver !== null ? $this->str($driver->national_id ?? null) : null),
                'photo_url' => $ownPhoto !== null
                    ? $this->fileUrl((string) ($profile->photo_disk ?? self::DOCUMENTS_DISK), $ownPhoto)
                    : ($photoPath !== null ? ImageFallback::diskUrl(null, $photoPath) : null),
            ];
        }

        return $out;
    }

    /**
     * Datos personales de la persona (cualquier rol). Salen de su perfil propio (`staff_profiles`); lo que
     * falte ahí se completa con su ficha de chofer, si la tiene.
     *
     * @param  array<string, mixed>|null                                                                                                                                     $driver
     * @return array{phone_secondary: string|null, birth_date: string|null, address: string|null, emergency_contact_name: string|null, emergency_contact_phone: string|null}
     */
    private function personal(int $userId, ?array $driver): array
    {
        $profile = null;
        try {
            $profile = DB::table('staff_profiles')->where('user_id', $userId)->first();
        } catch (Throwable) {
            // tabla ausente en este entorno.
        }

        $pick = fn (string $key): ?string => $this->str($profile->{$key} ?? null)
            ?? (is_string($driver[$key] ?? null) && $driver[$key] !== '' ? $driver[$key] : null);

        return [
            'phone_secondary' => $pick('phone_secondary'),
            'birth_date' => $this->date($profile->birth_date ?? null) ?? (is_string($driver['birth_date'] ?? null) ? $driver['birth_date'] : null),
            'address' => $pick('address'),
            'emergency_contact_name' => $pick('emergency_contact_name'),
            'emergency_contact_phone' => $pick('emergency_contact_phone'),
        ];
    }

    /**
     * Datos de chofer (licencia, contacto de emergencia…), o null si la persona no tiene ficha de chofer.
     *
     * @return array<string, mixed>|null
     */
    private function driver(?string $driverId): ?array
    {
        if ($driverId === null || $driverId === '') {
            return null;
        }

        try {
            $row = DB::table('fleet_drivers')->where('id', $driverId)->first();
        } catch (Throwable) {
            return null;
        }
        if (!$row instanceof stdClass) {
            return null;
        }

        return [
            'id' => $driverId,
            'active' => (bool) ($row->active ?? false),
            'license_number' => $this->str($row->license_number ?? null),
            'license_category' => $this->str($row->license_category ?? null),
            'license_expiry' => $this->date($row->license_expiry ?? null),
            'license_expired' => $this->isExpired($row->license_expiry ?? null),
            'birth_date' => $this->date($row->birth_date ?? null),
            'phone_secondary' => $this->str($row->phone_secondary ?? null),
            'address' => $this->str($row->address ?? null),
            'emergency_contact_name' => $this->str($row->emergency_contact_name ?? null),
            'emergency_contact_phone' => $this->str($row->emergency_contact_phone ?? null),
        ];
    }

    /**
     * Vehículo vigente del chofer: su tripulación de hoy (o la más reciente anterior). Null si no tiene.
     *
     * @return array{crew_id: string, vehicle_id: string, plate: string|null, assembled_for: string}|null
     */
    private function currentVehicle(?string $driverId): ?array
    {
        if ($driverId === null || $driverId === '') {
            return null;
        }

        try {
            $crew = DB::table('fleet_crews')
                ->where('driver_id', $driverId)
                ->whereDate('assembled_for', '<=', now()->toDateString())
                ->orderByDesc('assembled_for')
                ->first(['id', 'vehicle_id', 'assembled_for']);
            if (!$crew instanceof stdClass) {
                return null;
            }

            $plate = DB::table('fleet_vehicles')->where('id', $crew->vehicle_id)->value('plate');

            return [
                'crew_id' => (string) $crew->id,
                'vehicle_id' => (string) $crew->vehicle_id,
                'plate' => is_string($plate) && $plate !== '' ? $plate : null,
                'assembled_for' => substr((string) $crew->assembled_for, 0, 10),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function devices(int $userId): array
    {
        $out = [];
        try {
            $rows = DB::table('devices')->where('user_id', $userId)->orderByDesc('last_seen_at')
                ->get(['uuid', 'name', 'platform', 'model', 'os_version', 'app_version', 'session_bucket', 'last_seen_at', 'revoked_at']);
            foreach ($rows as $row) {
                $out[] = [
                    'id' => $this->str($row->uuid ?? null),
                    'name' => $this->str($row->name ?? null),
                    'platform' => $this->str($row->platform ?? null),
                    'model' => $this->str($row->model ?? null),
                    'os_version' => $this->str($row->os_version ?? null),
                    'app_version' => $this->str($row->app_version ?? null),
                    'session_bucket' => $this->str($row->session_bucket ?? null),
                    'last_seen_at' => $this->iso($row->last_seen_at ?? null),
                    'revoked' => ($row->revoked_at ?? null) !== null,
                ];
            }
        } catch (Throwable) {
            // sin dispositivos disponibles.
        }

        return $out;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function scans(int $userId, ?string $from, ?string $to, int $window): array
    {
        $query = DB::table('execution_scan_events as e')
            ->leftJoin('execution_deliveries as d', 'd.id', '=', 'e.delivery_id')
            ->leftJoin('shipments as s', 's.id', '=', 'd.shipment_id')
            ->where('e.user_id', (string) $userId);
        $this->between($query, 'e.occurred_at', $from, $to);

        $total = (clone $query)->count();
        $items = [];
        foreach ($query->orderByDesc('e.occurred_at')->limit($window)->get(['e.type', 'e.occurred_at', 's.external_id']) as $row) {
            $items[] = [
                'type' => 'scan',
                'at' => $this->iso($row->occurred_at ?? null) ?? '',
                'title' => (string) $row->type,
                'detail' => $this->str($row->external_id ?? null),
            ];
        }

        return [$items, $total];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function assignments(string $driverId, ?string $from, ?string $to, int $window): array
    {
        $query = DB::table('fleet_crews as c')->leftJoin('fleet_vehicles as v', 'v.id', '=', 'c.vehicle_id')->where('c.driver_id', $driverId);
        $this->between($query, 'c.assembled_for', $from, $to);

        $total = (clone $query)->count();
        $items = [];
        foreach ($query->orderByDesc('c.assembled_for')->limit($window)->get(['c.assembled_for', 'v.plate']) as $row) {
            $items[] = [
                'type' => 'assignment',
                'at' => $this->iso($row->assembled_for ?? null) ?? '',
                'title' => 'vehicle_assigned',
                'detail' => $this->str($row->plate ?? null),
            ];
        }

        return [$items, $total];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function checklists(string $driverId, ?string $from, ?string $to, int $window): array
    {
        $query = DB::table('fleet_pre_trip_checklists as p')->leftJoin('fleet_vehicles as v', 'v.id', '=', 'p.vehicle_id')->where('p.driver_id', $driverId);
        $this->between($query, 'p.performed_on', $from, $to);

        $total = (clone $query)->count();
        $items = [];
        foreach ($query->orderByDesc('p.performed_on')->limit($window)->get(['p.performed_on', 'p.passed', 'p.fit_to_dispatch', 'v.plate']) as $row) {
            $items[] = [
                'type' => 'checklist',
                'at' => $this->iso($row->performed_on ?? null) ?? '',
                'title' => (bool) ($row->passed ?? false) ? 'checklist_passed' : 'checklist_failed',
                'detail' => $this->str($row->plate ?? null),
            ];
        }

        return [$items, $total];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function deviceActivity(int $userId, ?string $from, ?string $to, int $window): array
    {
        $query = DB::table('devices')->where('user_id', $userId)->whereNotNull('last_seen_at');
        $this->between($query, 'last_seen_at', $from, $to);

        $total = (clone $query)->count();
        $items = [];
        foreach ($query->orderByDesc('last_seen_at')->limit($window)->get(['name', 'platform', 'last_seen_at']) as $row) {
            $items[] = [
                'type' => 'device',
                'at' => $this->iso($row->last_seen_at ?? null) ?? '',
                'title' => 'device_last_seen',
                'detail' => $this->str($row->name ?? null) ?? $this->str($row->platform ?? null),
            ];
        }

        return [$items, $total];
    }

    private function between(Builder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null && $from !== '') {
            $query->whereDate($column, '>=', $from);
        }
        if ($to !== null && $to !== '') {
            $query->whereDate($column, '<=', $to);
        }
    }

    private function profilePhoto(int $userId): ?string
    {
        try {
            $own = DB::table('staff_profiles')->where('user_id', $userId)->first(['photo_disk', 'photo_path']);
            if ($own instanceof stdClass && is_string($own->photo_path ?? null) && $own->photo_path !== '') {
                return $this->fileUrl((string) ($own->photo_disk ?? self::DOCUMENTS_DISK), $own->photo_path);
            }
        } catch (Throwable) {
            // sin perfil propio: se usa la foto de chofer si hay.
        }

        try {
            $path = DB::table('users as u')->join('fleet_drivers as d', 'd.id', '=', 'u.driver_id')->where('u.id', $userId)->value('d.photo_path');
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? ImageFallback::diskUrl(null, $path) : null;
    }

    /** URL visible de un archivo: firmada si está en un disco de evidencia; si no, la que dé el disco. */
    private function fileUrl(string $disk, string $path): ?string
    {
        return in_array($disk, EvidenceFileUrl::DISKS, true)
            ? EvidenceFileUrl::forDisk($disk, $path)
            : ImageFallback::diskUrl($disk, $path);
    }

    private function str(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    private function isExpired(mixed $value): bool
    {
        $date = $this->date($value);

        return $date !== null && $date < now()->toDateString();
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
