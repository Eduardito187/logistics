<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A5 — Backfill ADITIVO e IDEMPOTENTE de los documentos inline del ayudante a la tabla polimórfica
 * `fleet_documents`.
 *
 * Contexto: hoy los documentos del ayudante viven en DOS sitios — el jsonb inline `fleet_helpers.documents`
 * (capturado al registrar, lo leen las alertas de vencimiento del dominio) y la tabla `fleet_documents`
 * (store rico con archivo/referencia que añade el admin). Esta migración copia cada documento inline a
 * `fleet_documents` (owner_type='helper') para que esa tabla sea la fuente única, SIN borrar el jsonb
 * (no destructivo: la columna queda intacta como respaldo).
 *
 * Idempotente: no inserta un documento que ya exista para ese ayudante con el mismo tipo y vencimiento,
 * así que puede correrse más de una vez sin duplicar. Solo INSERTA; nunca borra ni actualiza.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sin fleet_documents no hay a dónde migrar; sin fleet_helpers no hay de dónde. Tolerante.
        if (!DB::getSchemaBuilder()->hasTable('fleet_documents') || !DB::getSchemaBuilder()->hasTable('fleet_helpers')) {
            return;
        }

        $now = now();

        DB::table('fleet_helpers')
            ->select('id', 'documents')
            ->orderBy('id')
            ->each(function (object $helper) use ($now): void {
                $ownerId = (string) $helper->id;

                // La columna es jsonb; según el driver llega como string JSON o como array ya decodificado.
                $documents = is_string($helper->documents) ? json_decode($helper->documents, true) : $helper->documents;
                if (!is_array($documents)) {
                    return;
                }

                foreach ($documents as $doc) {
                    if (!is_array($doc)) {
                        continue;
                    }
                    $type = isset($doc['type']) && is_string($doc['type']) ? $doc['type'] : null;
                    $expiresOn = isset($doc['expires_on']) && is_string($doc['expires_on']) ? $doc['expires_on'] : null;
                    if ($type === null || $expiresOn === null) {
                        continue;
                    }

                    // Idempotencia: no duplicar si ya existe el mismo documento (tipo + vencimiento) para el dueño.
                    $exists = DB::table('fleet_documents')
                        ->where('owner_type', 'helper')
                        ->where('owner_id', $ownerId)
                        ->where('document_type', $type)
                        ->whereDate('expires_on', $expiresOn)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('fleet_documents')->insert([
                        'id' => (string) Str::uuid7(),
                        'owner_type' => 'helper',
                        'owner_id' => $ownerId,
                        'document_type' => $type,
                        'expires_on' => $expiresOn,
                        'issued_on' => null,
                        'file_path' => null,
                        'note' => null,
                        'reference' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Backfill no destructivo: no se revierte (borrar documentos migrados podría eliminar datos que el
        // operador ya enriqueció después en fleet_documents). El jsonb inline sigue intacto como respaldo.
    }
};
