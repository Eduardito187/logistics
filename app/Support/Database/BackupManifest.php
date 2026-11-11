<?php

declare(strict_types=1);

namespace App\Support\Database;

use DateTimeInterface;

/**
 * Lógica PURA del manifest de un backup: comparación del inventario (estructura + conteo de filas) entre
 * lo que dice el manifest y lo que quedó en una base restaurada, parseo del TOC de `pg_restore --list` y
 * nombres de las bases auxiliares (scratch / prev). Sin I/O: se testea sin base de datos.
 *
 * Forma del inventario (`database.inventory` del manifest, producido por PostgresAdmin::inventory()):
 *   schemas, tables, partitioned_tables, indexes, constraints, views, materialized_views, functions,
 *   triggers: list<string> (nombres calificados, ordenados)
 *   sequences: array<string, int|null> (last_value; null = nunca usada)
 *   extensions: array<string, string> (nombre → versión)
 * y `database.row_counts`: array<string, int> (conteo EXACTO por tabla hoja; ver PostgresAdmin).
 */
final class BackupManifest
{
    /** Versión del formato del bundle/manifest. */
    public const FORMAT = 1;

    /** Claves del inventario que son listas de nombres (se comparan como conjuntos). */
    public const LIST_KEYS = [
        'schemas', 'tables', 'partitioned_tables', 'indexes', 'constraints',
        'views', 'materialized_views', 'functions', 'triggers',
    ];

    /**
     * Compara inventario + conteos esperados (manifest) contra los reales (base restaurada).
     * Devuelve las diferencias; vacío = idéntico.
     *
     * - Listas de objetos: faltantes y sobrantes.
     * - Extensiones: por nombre (la versión puede variar legítimamente si el servidor destino trae otra
     *   versión por defecto; no es una diferencia de datos).
     * - Secuencias: el valor restaurado debe ser >= al del manifest (el del manifest se lee justo ANTES del
     *   dump y las secuencias no son transaccionales: una escritura concurrente solo puede adelantarlas).
     * - Filas: conteo exacto por tabla.
     *
     * @param  array<string, mixed>                                        $expected
     * @param  array<string, mixed>                                        $actual
     * @return list<array{item: string, expected: string, actual: string}>
     */
    public static function compare(array $expected, array $actual): array
    {
        $diffs = [];
        $expectedInventory = self::arrayOf($expected['inventory'] ?? []);
        $actualInventory = self::arrayOf($actual['inventory'] ?? []);

        foreach (self::LIST_KEYS as $key) {
            $want = self::stringList($expectedInventory[$key] ?? []);
            $have = self::stringList($actualInventory[$key] ?? []);

            foreach (array_diff($want, $have) as $missing) {
                $diffs[] = ['item' => "{$key}: {$missing}", 'expected' => 'present', 'actual' => 'missing'];
            }
            foreach (array_diff($have, $want) as $extra) {
                $diffs[] = ['item' => "{$key}: {$extra}", 'expected' => 'absent', 'actual' => 'present'];
            }
        }

        $wantExt = array_keys(self::arrayOf($expectedInventory['extensions'] ?? []));
        $haveExt = array_keys(self::arrayOf($actualInventory['extensions'] ?? []));
        foreach (array_diff($wantExt, $haveExt) as $missing) {
            $diffs[] = ['item' => "extensions: {$missing}", 'expected' => 'present', 'actual' => 'missing'];
        }
        foreach (array_diff($haveExt, $wantExt) as $extra) {
            $diffs[] = ['item' => "extensions: {$extra}", 'expected' => 'absent', 'actual' => 'present'];
        }

        $wantSeq = self::arrayOf($expectedInventory['sequences'] ?? []);
        $haveSeq = self::arrayOf($actualInventory['sequences'] ?? []);
        foreach ($wantSeq as $name => $value) {
            if (!array_key_exists($name, $haveSeq)) {
                $diffs[] = ['item' => "sequences: {$name}", 'expected' => self::show($value), 'actual' => 'missing'];

                continue;
            }
            $restored = $haveSeq[$name];
            if (is_numeric($value) && (!is_numeric($restored) || (int) $restored < (int) $value)) {
                $diffs[] = ['item' => "sequences: {$name}", 'expected' => '>= '.self::show($value), 'actual' => self::show($restored)];
            }
        }
        foreach (array_diff_key($haveSeq, $wantSeq) as $name => $value) {
            $diffs[] = ['item' => "sequences: {$name}", 'expected' => 'absent', 'actual' => self::show($value)];
        }

        $wantRows = self::arrayOf($expected['row_counts'] ?? []);
        $haveRows = self::arrayOf($actual['row_counts'] ?? []);
        foreach ($wantRows as $table => $count) {
            $restored = $haveRows[$table] ?? null;
            if ($restored === null) {
                $diffs[] = ['item' => "rows: {$table}", 'expected' => self::show($count), 'actual' => 'missing'];
            } elseif ((int) self::show($restored) !== (int) self::show($count)) {
                $diffs[] = ['item' => "rows: {$table}", 'expected' => self::show($count), 'actual' => self::show($restored)];
            }
        }
        foreach (array_diff_key($haveRows, $wantRows) as $table => $count) {
            $diffs[] = ['item' => "rows: {$table}", 'expected' => 'absent', 'actual' => self::show($count)];
        }

        return $diffs;
    }

    /**
     * Verificación ESTRUCTURAL mínima (sin manifest, p. ej. un `.dump` viejo de db:backup): toda tabla del
     * TOC del dump tiene que existir en la base restaurada.
     *
     * @param  list<string>                                                $tocTables
     * @param  list<string>                                                $restoredTables
     * @return list<array{item: string, expected: string, actual: string}>
     */
    public static function compareToc(array $tocTables, array $restoredTables): array
    {
        $diffs = [];
        foreach (array_diff($tocTables, $restoredTables) as $missing) {
            $diffs[] = ['item' => "tables: {$missing}", 'expected' => 'present (TOC)', 'actual' => 'missing'];
        }

        return $diffs;
    }

    /**
     * Tablas (schema.nombre) declaradas en la salida de `pg_restore --list`.
     * Línea típica: `215; 1259 16390 TABLE public users logistics`. Se ignoran `TABLE DATA` y `TABLE ATTACH`
     * (datos y anexado de particiones), que no declaran tablas nuevas.
     *
     * @return list<string>
     */
    public static function tablesFromToc(string $listOutput): array
    {
        $tables = [];
        foreach (preg_split('/\R/', $listOutput) ?: [] as $line) {
            if (preg_match('/^\s*\d+;\s+\d+\s+\d+\s+TABLE\s+(?!DATA\s|ATTACH\s)(\S+)\s+(\S+)(\s|$)/', $line, $m) === 1) {
                $tables[] = $m[1].'.'.$m[2];
            }
        }
        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /**
     * Resumen legible (cantidades) del inventario + filas, para el manifest y la consola.
     *
     * @param  array<string, mixed> $database
     * @return array<string, int>
     */
    public static function summary(array $database): array
    {
        $inventory = self::arrayOf($database['inventory'] ?? []);
        $summary = [];
        foreach ([...self::LIST_KEYS, 'sequences', 'extensions'] as $key) {
            $summary[$key] = count(self::arrayOf($inventory[$key] ?? []));
        }
        $rows = self::arrayOf($database['row_counts'] ?? []);
        $summary['row_total'] = array_sum(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows));

        return $summary;
    }

    /**
     * Nombre de una base auxiliar: `<db>_<kind>_<YYYYmmdd_HHMMSS>` (kind = verify | restore | prev),
     * recortando la base para no pasar el límite de 63 bytes de los identificadores de Postgres.
     */
    public static function auxiliaryName(string $database, string $kind, DateTimeInterface $at): string
    {
        $suffix = '_'.$kind.'_'.$at->format('Ymd_His');

        return substr($database, 0, 63 - strlen($suffix)).$suffix;
    }

    /** ¿Es una base scratch descartable (verify/restore)? Guard para no dropear jamás otra cosa. */
    public static function isScratchName(string $name): bool
    {
        return preg_match('/_(verify|restore)_\d{8}_\d{6}$/', $name) === 1;
    }

    /** Identificador SQL entre comillas dobles (escapa comillas internas). */
    public static function quoteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /** @return array<array-key, mixed> */
    private static function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        return array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', self::arrayOf($value)));
    }

    private static function show(mixed $value): string
    {
        return $value === null ? 'null' : (is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR));
    }
}
