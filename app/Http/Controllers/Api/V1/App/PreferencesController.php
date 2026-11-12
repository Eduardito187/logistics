<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Support\UserPreferenceSchema;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preferencias del propio usuario (`/api/v1/app/me/preferences`, auditoría app #8).
 *
 * GET devuelve el documento completo; PUT hace MERGE (las claves no enviadas se conservan; `null` borra
 * una clave). Resolución de conflictos entre teléfonos: last-write-wins POR CLAVE con el instante del
 * cambio (`updated_at` del cuerpo, momento en que el usuario cambió la preferencia en el teléfono; por
 * defecto ahora). Un cambio más viejo que el último aceptado para esa clave se ignora y se informa en
 * `ignored_keys`, así un teléfono que estuvo offline no pisa lo que se cambió después en otro.
 */
final class PreferencesController
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preference = UserPreference::query()->where('user_id', $user->getKey())->first();

        return new JsonResponse(['data' => $this->present($preference)]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'preferences' => ['present', 'array'],
            'updated_at' => ['sometimes', 'nullable', 'date'],
        ]);

        /** @var array<array-key, mixed> $incoming */
        $incoming = (array) $request->input('preferences', []);
        $errors = UserPreferenceSchema::validate($incoming);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changedAt = $this->changedAt($request->input('updated_at'));

        /** @var User $user */
        $user = $request->user();
        $userId = (int) $user->getKey();

        // La fila se crea fuera de la transacción (createOrFirst absorbe la carrera de dos PUT
        // simultáneos por el unique de user_id) y luego se bloquea para el merge.
        if (!UserPreference::query()->where('user_id', $userId)->exists()) {
            UserPreference::query()->createOrFirst(['user_id' => $userId], ['preferences' => [], 'key_updated_at' => []]);
        }

        $preference = null;
        $ignored = [];
        DB::transaction(function () use ($userId, $incoming, $changedAt, &$preference, &$ignored): void {
            $row = UserPreference::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();

            $ignored = $this->merge($row, $incoming, $changedAt);
            $preference = $row;
        });

        return new JsonResponse(['data' => $this->present($preference) + ['ignored_keys' => $ignored]]);
    }

    /**
     * Aplica el merge last-write-wins por clave sobre la fila bloqueada y la guarda. Devuelve las claves
     * ignoradas por ser más viejas que el último cambio aceptado.
     *
     * @param  array<array-key, mixed> $incoming
     * @return list<string>
     */
    private function merge(UserPreference $row, array $incoming, Carbon $changedAt): array
    {
        $values = $row->preferences;
        $stamps = $row->key_updated_at;
        $ignored = [];

        foreach ($incoming as $key => $value) {
            $key = (string) $key;
            $storedAt = isset($stamps[$key]) ? Carbon::parse($stamps[$key]) : null;

            if ($storedAt !== null && $changedAt->lessThan($storedAt)) {
                $ignored[] = $key;

                continue;
            }

            if ($value === null) {
                unset($values[$key]);
            } else {
                $values[$key] = UserPreferenceSchema::normalize($key, $value);
            }
            // La marca queda aunque se borre (lápida): así un "set" viejo no resucita una clave borrada.
            $stamps[$key] = $changedAt->toIso8601ZuluString('millisecond');
        }

        if (count($values) > UserPreferenceSchema::MAX_STORED_KEYS) {
            throw ValidationException::withMessages([
                'preferences' => ['At most '.UserPreferenceSchema::MAX_STORED_KEYS.' preferences can be stored.'],
            ]);
        }

        ksort($values);
        $row->forceFill(['preferences' => $values, 'key_updated_at' => $stamps]);
        // Se toca updated_at aunque no cambie nada: es "último sync aceptado".
        $row->touch();

        return $ignored;
    }

    /**
     * Instante del cambio enviado por el cliente, acotado a "ahora" (un reloj del teléfono adelantado no
     * puede ganar para siempre). Sin valor = ahora.
     */
    private function changedAt(mixed $raw): Carbon
    {
        $now = Carbon::now();

        if (!is_string($raw) || $raw === '') {
            return $now;
        }

        $parsed = Carbon::parse($raw);

        return $parsed->greaterThan($now) ? $now : $parsed;
    }

    /**
     * @return array{preferences: array<string, mixed>|object, updated_at: string|null}
     */
    private function present(?UserPreference $preference): array
    {
        $values = $preference !== null ? $preference->preferences : [];
        // Orden estable por clave (jsonb de Postgres no conserva el orden de inserción).
        ksort($values);

        return [
            // Objeto JSON aunque esté vacío (un array PHP vacío se serializaría como []).
            'preferences' => $values === [] ? (object) [] : $values,
            'updated_at' => $preference?->updated_at?->toIso8601String(),
        ];
    }
}
