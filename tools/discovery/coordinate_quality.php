<?php

declare(strict_types=1);

/**
 * F0-GEO-01 — Análisis de calidad de coordenadas (toolkit de Discovery, Fase 0).
 *
 * Herramienta CLI de un solo archivo, SIN dependencias de Composer (solo stdlib de PHP). Lee el export
 * de direcciones/coordenadas actuales (ver docs/discovery/templates/coordinates-input-template.csv) y
 * reporta métricas de calidad: completitud, duplicados exactos, coordenadas fuera de rango, fuera del
 * bounding box aproximado de Bolivia, baja precisión, y un score de calidad global 0..100.
 *
 * Uso:
 *   php tools/discovery/coordinate_quality.php archivo.csv
 *
 * El objetivo es dar números reales antes de decidir cuánto hay que geocodificar o re-capturar, y cómo
 * se alimentará el score de confianza de dirección de `shipments` (ver docs/discovery/03-coordinate-quality.md).
 */

// ---------------------------------------------------------------------------------------------------
// Constantes de dominio del análisis
// ---------------------------------------------------------------------------------------------------

/**
 * Bounding box APROXIMADO de Bolivia en grados decimales (WGS84). Es un rectángulo, no la frontera real:
 * sirve para detectar coordenadas groseramente fuera del país (en el océano, en otro continente, con el
 * signo invertido), no para validar límites departamentales. Una coordenada dentro del box todavía puede
 * caer fuera de la cobertura real; eso lo resuelve `coverage` (punto→zona con PostGIS), no esta herramienta.
 */
const BOLIVIA_LAT_MIN = -23.0;
const BOLIVIA_LAT_MAX = -9.5;
const BOLIVIA_LNG_MIN = -70.0;
const BOLIVIA_LNG_MAX = -57.0;

/** Rango físicamente válido de una coordenada WGS84. */
const LAT_ABS_MAX = 90.0;
const LNG_ABS_MAX = 180.0;

/**
 * Mínimo de decimales para considerar la precisión aceptable. 4 decimales ≈ 11 m en el ecuador, suficiente
 * para ubicar una fachada; con 0..3 decimales (≈ >100 m) el chofer termina llamando al cliente. Umbral
 * documentado y aproximado.
 */
const MIN_DECIMALS = 4;

/** Encabezados esperados del CSV de entrada (orden y nombres). */
const EXPECTED_HEADERS = ['id', 'customer', 'address_text', 'lat', 'lng', 'department', 'source'];

/**
 * Pesos del score de calidad global 0..100. Suman 1.0. Cada componente es una tasa 0..1 sobre el total
 * de filas; el score es su combinación lineal ×100. Ver `computeScore()` y la doc para la justificación.
 */
const WEIGHT_COMPLETENESS = 0.40; // tener coordenada usable es lo que más pesa: sin punto no hay ruta
const WEIGHT_VALIDITY = 0.30;     // en rango y dentro del box de Bolivia
const WEIGHT_PRECISION = 0.20;    // suficientes decimales para ubicar la fachada
const WEIGHT_UNIQUENESS = 0.10;   // no ser parte de un duplicado exacto

// ---------------------------------------------------------------------------------------------------
// Punto de entrada
// ---------------------------------------------------------------------------------------------------

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    if (count($argv) !== 2) {
        fwrite(STDERR, "Uso: php tools/discovery/coordinate_quality.php archivo.csv\n");

        return 2;
    }

    $path = $argv[1];
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "Error: no se puede leer el archivo '{$path}'.\n");

        return 2;
    }

    try {
        $rows = readCsv($path);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'Error: '.$e->getMessage()."\n");

        return 2;
    }

    if ($rows === []) {
        fwrite(STDERR, "Error: el CSV no tiene filas de datos.\n");

        return 2;
    }

    $analysis = analyze($rows);
    renderReport($path, $analysis);

    return 0;
}

// ---------------------------------------------------------------------------------------------------
// Lectura de CSV
// ---------------------------------------------------------------------------------------------------

/**
 * Lee el CSV y devuelve una lista de filas asociativas con las claves de EXPECTED_HEADERS.
 *
 * @return list<array<string, string>>
 *
 * @throws RuntimeException si faltan encabezados obligatorios.
 */
function readCsv(string $path): array
{
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException("no se pudo abrir '{$path}'.");
    }

    try {
        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            throw new RuntimeException('el CSV está vacío.');
        }

        // Normalizamos los encabezados (minúsculas, sin espacios ni BOM) para ser tolerantes con exports.
        $header = array_map(
            static fn (?string $h): string => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            $header,
        );

        $missing = array_diff(EXPECTED_HEADERS, $header);
        if ($missing !== []) {
            throw new RuntimeException('faltan columnas obligatorias: '.implode(', ', $missing).'.');
        }

        /** @var array<string, int> $index columna => posición */
        $index = array_flip($header);

        $rows = [];
        while (($record = fgetcsv($handle)) !== false) {
            if ($record === null) {
                continue;
            }
            // Saltamos líneas completamente vacías.
            if (count($record) === 1 && trim((string) $record[0]) === '') {
                continue;
            }

            $row = [];
            foreach (EXPECTED_HEADERS as $col) {
                $pos = $index[$col];
                $row[$col] = isset($record[$pos]) ? trim((string) $record[$pos]) : '';
            }
            $rows[] = $row;
        }

        return $rows;
    } finally {
        fclose($handle);
    }
}

// ---------------------------------------------------------------------------------------------------
// Análisis
// ---------------------------------------------------------------------------------------------------

/**
 * Clasifica cada fila y acumula las métricas globales y por departamento.
 *
 * @param list<array<string, string>> $rows
 * @return array{
 *     total: int,
 *     withCoords: int,
 *     withoutCoords: int,
 *     outOfRange: int,
 *     outOfBolivia: int,
 *     lowPrecision: int,
 *     duplicateGroups: int,
 *     duplicateRows: int,
 *     valid: int,
 *     unique: int,
 *     precise: int,
 *     score: float,
 *     byDepartment: array<string, array{total: int, withCoords: int, valid: int, issues: int}>,
 *     duplicateDetail: list<array{key: string, count: int, ids: list<string>}>
 * }
 */
function analyze(array $rows): array
{
    $total = count($rows);
    $withCoords = 0;
    $outOfRange = 0;
    $outOfBolivia = 0;
    $lowPrecision = 0;
    $valid = 0;   // con coordenada, en rango y dentro del box de Bolivia
    $precise = 0; // con coordenada y con suficientes decimales

    /** @var array<string, list<string>> $coordGroups "lat,lng" => ids con esa coordenada exacta */
    $coordGroups = [];

    /** @var array<string, array{total: int, withCoords: int, valid: int, issues: int}> $byDepartment */
    $byDepartment = [];

    foreach ($rows as $row) {
        $dept = $row['department'] !== '' ? $row['department'] : '(sin department)';
        if (!isset($byDepartment[$dept])) {
            $byDepartment[$dept] = ['total' => 0, 'withCoords' => 0, 'valid' => 0, 'issues' => 0];
        }
        $byDepartment[$dept]['total']++;

        $hasCoords = $row['lat'] !== '' && $row['lng'] !== '' && is_numeric($row['lat']) && is_numeric($row['lng']);
        if (!$hasCoords) {
            // Sin coordenada usable: es un problema en sí mismo (incompletitud).
            $byDepartment[$dept]['issues']++;

            continue;
        }

        $withCoords++;
        $byDepartment[$dept]['withCoords']++;

        $lat = (float) $row['lat'];
        $lng = (float) $row['lng'];

        $inRange = $lat >= -LAT_ABS_MAX && $lat <= LAT_ABS_MAX && $lng >= -LNG_ABS_MAX && $lng <= LNG_ABS_MAX;
        $inBolivia = $inRange
            && $lat >= BOLIVIA_LAT_MIN && $lat <= BOLIVIA_LAT_MAX
            && $lng >= BOLIVIA_LNG_MIN && $lng <= BOLIVIA_LNG_MAX;
        $enoughDecimals = decimalPlaces($row['lat']) >= MIN_DECIMALS && decimalPlaces($row['lng']) >= MIN_DECIMALS;

        $rowHasIssue = false;

        if (!$inRange) {
            $outOfRange++;
            $rowHasIssue = true;
        } elseif (!$inBolivia) {
            // Solo cuenta como "fuera de Bolivia" si al menos es una coordenada en rango.
            $outOfBolivia++;
            $rowHasIssue = true;
        }

        if (!$enoughDecimals) {
            $lowPrecision++;
            $rowHasIssue = true;
        } else {
            $precise++;
        }

        if ($inBolivia) {
            $valid++;
        }

        if ($rowHasIssue) {
            $byDepartment[$dept]['issues']++;
        }

        // Agrupamos por la coordenada exacta tal como viene en el texto (duplicado exacto).
        $key = $row['lat'].','.$row['lng'];
        $coordGroups[$key][] = $row['id'] !== '' ? $row['id'] : '(sin id)';
    }

    // Duplicados exactos: grupos con más de una fila.
    $duplicateGroups = 0;
    $duplicateRows = 0;
    $duplicateDetail = [];
    foreach ($coordGroups as $key => $ids) {
        if (count($ids) > 1) {
            $duplicateGroups++;
            $duplicateRows += count($ids);
            $duplicateDetail[] = ['key' => $key, 'count' => count($ids), 'ids' => $ids];
        }
    }
    // Las filas únicas (no parte de ningún duplicado) entre las que tienen coordenada.
    $unique = $withCoords - $duplicateRows;

    $score = computeScore($total, $withCoords, $valid, $precise, $unique);

    return [
        'total' => $total,
        'withCoords' => $withCoords,
        'withoutCoords' => $total - $withCoords,
        'outOfRange' => $outOfRange,
        'outOfBolivia' => $outOfBolivia,
        'lowPrecision' => $lowPrecision,
        'duplicateGroups' => $duplicateGroups,
        'duplicateRows' => $duplicateRows,
        'valid' => $valid,
        'unique' => $unique,
        'precise' => $precise,
        'score' => $score,
        'byDepartment' => $byDepartment,
        'duplicateDetail' => $duplicateDetail,
    ];
}

/**
 * Cuenta los decimales en la representación textual de un número ("-17.75" => 2, "-17" => 0).
 *
 * Se trabaja sobre el texto original del CSV a propósito: así "−17" (0 decimales, precisión groseramente
 * baja) se distingue de "−17.7833" aunque numéricamente sean parecidos. Los ceros a la derecha cuentan
 * como decimales declarados; es una heurística aproximada de precisión, no una medida de exactitud real.
 */
function decimalPlaces(string $number): int
{
    $number = trim($number);
    $dot = strpos($number, '.');
    if ($dot === false) {
        return 0;
    }

    return strlen(substr($number, $dot + 1));
}

/**
 * Score de calidad global 0..100.
 *
 * Fórmula: combinación lineal de cuatro tasas, cada una medida sobre el TOTAL de filas (no solo sobre las
 * que tienen coordenada), de modo que una fila sin coordenada penaliza todos los componentes:
 *
 *   score = 100 × ( 0.40·completitud + 0.30·validez + 0.20·precisión + 0.10·unicidad )
 *
 *   completitud = filas con coordenada usable         / total
 *   validez     = filas en rango y dentro de Bolivia   / total
 *   precisión   = filas con ≥ 4 decimales en lat y lng / total
 *   unicidad    = filas no duplicadas (coord. única)   / total
 *
 * Un dataset perfecto (todo completo, en Bolivia, preciso y sin duplicados) da 100. Un dataset vacío de
 * coordenadas da 0. Los pesos reflejan la prioridad operativa: sin punto no hay ruta (completitud manda),
 * y entre coordenadas presentes importa más que apunten a Bolivia que la precisión fina o la unicidad.
 */
function computeScore(int $total, int $withCoords, int $valid, int $precise, int $unique): float
{
    if ($total === 0) {
        return 0.0;
    }

    $completeness = $withCoords / $total;
    $validity = $valid / $total;
    $precision = $precise / $total;
    $uniqueness = $unique / $total;

    $score = 100.0 * (
        WEIGHT_COMPLETENESS * $completeness
        + WEIGHT_VALIDITY * $validity
        + WEIGHT_PRECISION * $precision
        + WEIGHT_UNIQUENESS * $uniqueness
    );

    return round($score, 1);
}

// ---------------------------------------------------------------------------------------------------
// Presentación (tablas de texto)
// ---------------------------------------------------------------------------------------------------

/**
 * @param array{
 *     total: int, withCoords: int, withoutCoords: int, outOfRange: int, outOfBolivia: int,
 *     lowPrecision: int, duplicateGroups: int, duplicateRows: int, valid: int, unique: int,
 *     precise: int, score: float,
 *     byDepartment: array<string, array{total: int, withCoords: int, valid: int, issues: int}>,
 *     duplicateDetail: list<array{key: string, count: int, ids: list<string>}>
 * } $a
 */
function renderReport(string $path, array $a): void
{
    $total = $a['total'];

    echo "\n";
    echo "============================================================\n";
    echo " F0-GEO-01 — Calidad de coordenadas\n";
    echo ' Archivo: '.$path."\n";
    echo "============================================================\n\n";

    // --- Métricas globales ---------------------------------------------------------------------
    echo "Métricas globales\n";
    $globalRows = [
        ['Total de filas', (string) $total, '—'],
        ['Con coordenadas', (string) $a['withCoords'], pct($a['withCoords'], $total)],
        ['Sin coordenadas (nulas/vacías)', (string) $a['withoutCoords'], pct($a['withoutCoords'], $total)],
        ['Fuera de rango (lat>90 o lng fuera -180..180)', (string) $a['outOfRange'], pct($a['outOfRange'], $total)],
        ['Fuera del box aproximado de Bolivia', (string) $a['outOfBolivia'], pct($a['outOfBolivia'], $total)],
        ['Baja precisión (< '.MIN_DECIMALS.' decimales)', (string) $a['lowPrecision'], pct($a['lowPrecision'], $total)],
    ];
    renderTable(['Métrica', 'Cantidad', '% del total'], $globalRows);
    echo "\n";

    // --- Duplicados exactos --------------------------------------------------------------------
    echo "Duplicados exactos (misma lat/lng en distintas filas)\n";
    $dupRows = [
        ['Grupos duplicados', (string) $a['duplicateGroups']],
        ['Filas involucradas', (string) $a['duplicateRows']],
    ];
    renderTable(['Métrica', 'Cantidad'], $dupRows);
    if ($a['duplicateDetail'] !== []) {
        echo "\n  Detalle de grupos:\n";
        foreach ($a['duplicateDetail'] as $group) {
            echo '    - '.$group['key'].'  ('.$group['count'].' filas: ids '.implode(', ', $group['ids']).")\n";
        }
    }
    echo "\n";

    // --- Desglose por departamento -------------------------------------------------------------
    echo "Desglose por department\n";
    $deptRows = [];
    $byDept = $a['byDepartment'];
    ksort($byDept);
    foreach ($byDept as $dept => $m) {
        $deptRows[] = [
            $dept,
            (string) $m['total'],
            (string) $m['withCoords'],
            pct($m['withCoords'], $m['total']),
            (string) $m['issues'],
        ];
    }
    renderTable(['Department', 'Filas', 'Con coord.', '% compl.', 'Con problemas'], $deptRows);
    echo "\n";

    // --- Score global --------------------------------------------------------------------------
    echo "Score de calidad global\n";
    $scoreRows = [
        ['Completitud (peso '.fmtWeight(WEIGHT_COMPLETENESS).')', rate($a['withCoords'], $total)],
        ['Validez — en rango y en Bolivia (peso '.fmtWeight(WEIGHT_VALIDITY).')', rate($a['valid'], $total)],
        ['Precisión — ≥ '.MIN_DECIMALS.' decimales (peso '.fmtWeight(WEIGHT_PRECISION).')', rate($a['precise'], $total)],
        ['Unicidad — sin duplicados (peso '.fmtWeight(WEIGHT_UNIQUENESS).')', rate($a['unique'], $total)],
    ];
    renderTable(['Componente', 'Tasa'], $scoreRows);
    echo "\n";
    echo '  SCORE GLOBAL: '.number_format($a['score'], 1).' / 100'."\n";
    echo '  Fórmula: 100 × (0.40·completitud + 0.30·validez + 0.20·precisión + 0.10·unicidad)'."\n\n";
}

/**
 * Renderiza una tabla de texto con columnas alineadas al ancho de su contenido.
 *
 * @param list<string>       $headers
 * @param list<list<string>> $rows
 */
function renderTable(array $headers, array $rows): void
{
    $widths = [];
    foreach ($headers as $i => $h) {
        $widths[$i] = mb_strlen($h);
    }
    foreach ($rows as $row) {
        foreach ($row as $i => $cell) {
            $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
        }
    }

    $line = static function (array $cells) use ($widths): string {
        $parts = [];
        foreach ($cells as $i => $cell) {
            $parts[] = padCell((string) $cell, $widths[$i]);
        }

        return '  | '.implode(' | ', $parts).' |';
    };

    $separator = static function () use ($widths): string {
        $parts = [];
        foreach ($widths as $w) {
            $parts[] = str_repeat('-', $w);
        }

        return '  +-'.implode('-+-', $parts).'-+';
    };

    echo $separator()."\n";
    echo $line($headers)."\n";
    echo $separator()."\n";
    foreach ($rows as $row) {
        echo $line($row)."\n";
    }
    echo $separator()."\n";
}

/**
 * Rellena una celda con espacios a la derecha hasta el ancho dado (alineación a la izquierda), respetando
 * caracteres multibyte (acentos).
 */
function padCell(string $value, int $width): string
{
    $pad = $width - mb_strlen($value);

    return $pad > 0 ? $value.str_repeat(' ', $pad) : $value;
}

/** Porcentaje formateado "12.5%" de parte/total; "—" si el total es 0. */
function pct(int $part, int $total): string
{
    if ($total === 0) {
        return '—';
    }

    return number_format(100.0 * $part / $total, 1).'%';
}

/** Tasa formateada "3/15 = 20.0%". */
function rate(int $part, int $total): string
{
    if ($total === 0) {
        return '—';
    }

    return $part.'/'.$total.' = '.number_format(100.0 * $part / $total, 1).'%';
}

/** Formatea un peso 0..1 como porcentaje entero "40%". */
function fmtWeight(float $weight): string
{
    return ((int) round($weight * 100)).'%';
}

exit(main($argv));
