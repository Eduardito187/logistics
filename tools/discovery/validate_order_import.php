<?php

declare(strict_types=1);

/**
 * Validador de la plantilla de ingreso manual / Excel de pedidos (Fase 0 — Discovery, F0-SRC-03).
 *
 * Lee un CSV con una línea de pedido por renglón y comprueba que cada fila pueda convertirse, sin
 * ambigüedad, en lo que necesita la API de ingreso del contexto `shipments` (comando ReceiveShipment):
 * referencia de origen idempotente (order_external_id + source), dirección, y líneas con cantidad.
 *
 * Es una herramienta de discovery: solo usa la biblioteca estándar de PHP (fgetcsv), sin Composer,
 * sin Laravel y sin tocar el dominio. No crea envíos: valida el archivo y reporta.
 *
 * Uso:
 *   php tools/discovery/validate_order_import.php archivo.csv
 *
 * Códigos de salida:
 *   0  el archivo es válido (no hay errores)
 *   1  el archivo tiene errores de validación
 *   2  error de uso (argumento faltante, archivo ilegible, cabecera inválida)
 */

/** Columnas esperadas, en orden, en la cabecera del CSV. */
const COLUMNAS_ESPERADAS = [
    'order_external_id',
    'source',
    'customer_name',
    'customer_phone',
    'address_text',
    'address_reference',
    'lat',
    'lng',
    'delivery_date',
    'sku',
    'product_name',
    'quantity',
    'pieces_per_unit',
];

/** Columnas obligatorias: no pueden venir vacías en ninguna fila. */
const COLUMNAS_REQUERIDAS = [
    'order_external_id',
    'source',
    'customer_name',
    'address_text',
    'sku',
    'product_name',
    'quantity',
    'pieces_per_unit',
];

/**
 * Fuentes aceptadas (en minúsculas). Se corresponden 1:1 con el enum OrderSource del dominio, que las
 * guarda en MAYÚSCULAS; el futuro comando de carga las normaliza con strtoupper().
 */
const FUENTES_VALIDAS = ['sap', 'magento', 'store', 'manual', 'excel'];

/** Rango válido de latitud (grados decimales, WGS84), igual que el value object GeoPoint del dominio. */
const LAT_MIN = -90.0;
const LAT_MAX = 90.0;

/** Rango válido de longitud (grados decimales, WGS84), igual que GeoPoint. */
const LNG_MIN = -180.0;
const LNG_MAX = 180.0;

/**
 * Punto de entrada: orquesta la validación y devuelve el código de salida del proceso.
 *
 * @param list<string> $argv
 */
function main(array $argv): int
{
    if (count($argv) !== 2) {
        fwrite(STDERR, "Uso: php {$argv[0]} archivo.csv\n");

        return 2;
    }

    $ruta = $argv[1];
    if (!is_file($ruta) || !is_readable($ruta)) {
        fwrite(STDERR, "No se puede leer el archivo: {$ruta}\n");

        return 2;
    }

    $handle = fopen($ruta, 'r');
    if ($handle === false) {
        fwrite(STDERR, "No se pudo abrir el archivo: {$ruta}\n");

        return 2;
    }

    try {
        return validar($handle, $ruta);
    } finally {
        fclose($handle);
    }
}

/**
 * Recorre el CSV ya abierto, acumula errores por fila y agregados por pedido, e imprime el reporte.
 *
 * @param resource $handle
 */
function validar($handle, string $ruta): int
{
    $cabecera = fgetcsv($handle);
    if ($cabecera === false || $cabecera === null) {
        fwrite(STDERR, "El archivo está vacío: {$ruta}\n");

        return 2;
    }

    // Quitar el BOM UTF-8 de la primera celda, si lo hubiera (Excel suele agregarlo).
    if (isset($cabecera[0]) && is_string($cabecera[0])) {
        $cabecera[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecera[0]) ?? $cabecera[0];
    }

    if ($cabecera !== COLUMNAS_ESPERADAS) {
        fwrite(STDERR, "Cabecera inválida.\n");
        fwrite(STDERR, '  Esperada: '.implode(',', COLUMNAS_ESPERADAS)."\n");
        fwrite(STDERR, '  Recibida: '.implode(',', array_map('strval', $cabecera))."\n");

        return 2;
    }

    /** @var list<array{fila: int, errores: list<string>}> $erroresPorFila */
    $erroresPorFila = [];

    /**
     * Agregados por pedido para contar pedidos/líneas/bultos y detectar datos de cabecera inconsistentes
     * entre líneas del mismo order_external_id.
     *
     * @var array<string, array{lineas: int, bultos: int, firma: string, filaPrimera: int}> $pedidos
     */
    $pedidos = [];

    $totalLineas = 0;
    $numeroFila = 1; // La cabecera es la fila 1; los datos empiezan en la 2.

    while (($fila = fgetcsv($handle)) !== false) {
        if ($fila === null) {
            continue;
        }
        $numeroFila++;

        // Saltar renglones totalmente vacíos (p. ej. una línea en blanco al final del archivo).
        if (esFilaVacia($fila)) {
            continue;
        }

        $totalLineas++;
        $errores = [];

        if (count($fila) !== count(COLUMNAS_ESPERADAS)) {
            $errores[] = sprintf(
                'número de columnas incorrecto: se esperaban %d, hay %d',
                count(COLUMNAS_ESPERADAS),
                count($fila),
            );
            $erroresPorFila[] = ['fila' => $numeroFila, 'errores' => $errores];

            continue;
        }

        /** @var array<string, string> $registro */
        $registro = array_combine(COLUMNAS_ESPERADAS, array_map('trim', array_map('strval', $fila)));

        validarRequeridos($registro, $errores);
        validarFuente($registro, $errores);
        validarEntero($registro, 'quantity', $errores);
        validarEntero($registro, 'pieces_per_unit', $errores);
        validarCoordenadas($registro, $errores);

        if ($errores !== []) {
            $erroresPorFila[] = ['fila' => $numeroFila, 'errores' => $errores];
        }

        acumularPedido($pedidos, $registro, $numeroFila, $errores);
    }

    // Revisar consistencia de la cabecera de pedido entre líneas del mismo order_external_id.
    foreach ($pedidos as $id => $datos) {
        if ($datos['firma'] === '__INCONSISTENTE__') {
            $erroresPorFila[] = [
                'fila' => $datos['filaPrimera'],
                'errores' => ["el pedido '{$id}' tiene líneas con datos de cabecera distintos (source/cliente/dirección): deben coincidir"],
            ];
        }
    }

    usort($erroresPorFila, static fn (array $a, array $b): int => $a['fila'] <=> $b['fila']);

    return imprimirReporte($ruta, count($pedidos), $totalLineas, $pedidos, $erroresPorFila);
}

/**
 * ¿La fila está completamente vacía? (todas las celdas en blanco tras recortar espacios).
 *
 * @param list<string|null> $fila
 */
function esFilaVacia(array $fila): bool
{
    foreach ($fila as $celda) {
        if (trim((string) $celda) !== '') {
            return false;
        }
    }

    return true;
}

/**
 * Comprueba que todas las columnas obligatorias tengan valor.
 *
 * @param array<string, string> $registro
 * @param list<string>          $errores
 */
function validarRequeridos(array $registro, array &$errores): void
{
    foreach (COLUMNAS_REQUERIDAS as $columna) {
        if (($registro[$columna] ?? '') === '') {
            $errores[] = "campo requerido vacío: {$columna}";
        }
    }
}

/**
 * Comprueba que `source` sea una de las fuentes válidas (sin distinguir mayúsculas/minúsculas).
 *
 * @param array<string, string> $registro
 * @param list<string>          $errores
 */
function validarFuente(array $registro, array &$errores): void
{
    $source = $registro['source'] ?? '';
    if ($source === '') {
        return; // Ya lo reporta validarRequeridos().
    }

    if (!in_array(strtolower($source), FUENTES_VALIDAS, true)) {
        $errores[] = sprintf("source inválido '%s': debe ser uno de {%s}", $source, implode(',', FUENTES_VALIDAS));
    }
}

/**
 * Comprueba que una columna numérica sea un entero >= 1.
 *
 * @param array<string, string> $registro
 * @param list<string>          $errores
 */
function validarEntero(array $registro, string $columna, array &$errores): void
{
    $valor = $registro[$columna] ?? '';
    if ($valor === '') {
        return; // Ya lo reporta validarRequeridos().
    }

    if (!ctype_digit($valor) || (int) $valor < 1) {
        $errores[] = "{$columna} debe ser un entero >= 1: '{$valor}'";
    }
}

/**
 * Valida lat/lng: son opcionales, pero si viene una debe venir la otra, ambas numéricas y en rango.
 *
 * @param array<string, string> $registro
 * @param list<string>          $errores
 */
function validarCoordenadas(array $registro, array &$errores): void
{
    $lat = $registro['lat'] ?? '';
    $lng = $registro['lng'] ?? '';

    if ($lat === '' && $lng === '') {
        return; // Sin coordenadas: válido (dirección quedará de baja confianza hasta geocodificar).
    }

    if ($lat === '' || $lng === '') {
        $errores[] = 'lat y lng deben venir juntas (ambas) o ninguna';

        return;
    }

    if (!esNumero($lat)) {
        $errores[] = "lat no es numérica: '{$lat}'";
    } elseif ((float) $lat < LAT_MIN || (float) $lat > LAT_MAX) {
        $errores[] = sprintf("lat fuera de rango (%.0f..%.0f): '%s'", LAT_MIN, LAT_MAX, $lat);
    }

    if (!esNumero($lng)) {
        $errores[] = "lng no es numérica: '{$lng}'";
    } elseif ((float) $lng < LNG_MIN || (float) $lng > LNG_MAX) {
        $errores[] = sprintf("lng fuera de rango (%.0f..%.0f): '%s'", LNG_MIN, LNG_MAX, $lng);
    }
}

/**
 * ¿La cadena representa un número decimal (con signo opcional)?
 */
function esNumero(string $valor): bool
{
    return preg_match('/^[+-]?\d+(\.\d+)?$/', $valor) === 1;
}

/**
 * Acumula una línea en su pedido: suma líneas y bultos estimados, y marca inconsistencias de cabecera.
 *
 * Bultos estimados = suma de `quantity` de las líneas válidas (un bulto por unidad de producto, igual
 * que hace el dominio al recibir). `pieces_per_unit` NO multiplica bultos: describe las piezas dentro
 * de una misma unidad.
 *
 * @param array<string, array{lineas: int, bultos: int, firma: string, filaPrimera: int}> $pedidos
 * @param array<string, string>                                                            $registro
 * @param list<string>                                                                     $errores
 */
function acumularPedido(array &$pedidos, array $registro, int $numeroFila, array $errores): void
{
    $id = $registro['order_external_id'] ?? '';
    if ($id === '') {
        return; // Sin id no se puede agrupar; el error ya se reportó.
    }

    // Firma de los datos a nivel pedido: deben coincidir entre todas sus líneas.
    $firma = implode('|', [
        strtolower($registro['source'] ?? ''),
        $registro['customer_name'] ?? '',
        $registro['customer_phone'] ?? '',
        $registro['address_text'] ?? '',
        $registro['address_reference'] ?? '',
        $registro['lat'] ?? '',
        $registro['lng'] ?? '',
        $registro['delivery_date'] ?? '',
    ]);

    // Solo contar bultos de líneas cuya cantidad es válida, para no inflar el estimado.
    $cantidad = $registro['quantity'] ?? '';
    $bultos = (ctype_digit($cantidad) && (int) $cantidad >= 1) ? (int) $cantidad : 0;

    if (!isset($pedidos[$id])) {
        $pedidos[$id] = [
            'lineas' => 1,
            'bultos' => $bultos,
            'firma' => $firma,
            'filaPrimera' => $numeroFila,
        ];

        return;
    }

    $pedidos[$id]['lineas']++;
    $pedidos[$id]['bultos'] += $bultos;
    if ($pedidos[$id]['firma'] !== '__INCONSISTENTE__' && $pedidos[$id]['firma'] !== $firma) {
        $pedidos[$id]['firma'] = '__INCONSISTENTE__';
    }
}

/**
 * Imprime el resumen, la tabla de errores y devuelve el código de salida (0 ok, 1 con errores).
 *
 * @param array<string, array{lineas: int, bultos: int, firma: string, filaPrimera: int}> $pedidos
 * @param list<array{fila: int, errores: list<string>}>                                    $erroresPorFila
 */
function imprimirReporte(string $ruta, int $totalPedidos, int $totalLineas, array $pedidos, array $erroresPorFila): int
{
    $totalBultos = 0;
    foreach ($pedidos as $datos) {
        $totalBultos += $datos['bultos'];
    }

    echo "\n";
    echo "Validación de ingreso de pedidos\n";
    echo "Archivo: {$ruta}\n";
    echo str_repeat('=', 60)."\n";
    echo sprintf("Pedidos (order_external_id únicos) : %d\n", $totalPedidos);
    echo sprintf("Líneas de pedido                   : %d\n", $totalLineas);
    echo sprintf("Bultos estimados (suma quantity)   : %d\n", $totalBultos);
    echo str_repeat('=', 60)."\n";

    $totalErrores = 0;
    foreach ($erroresPorFila as $entrada) {
        $totalErrores += count($entrada['errores']);
    }

    if ($erroresPorFila === []) {
        echo "\nResultado: OK — no se encontraron errores.\n\n";

        return 0;
    }

    echo "\nErrores encontrados: {$totalErrores}\n";
    echo str_repeat('-', 60)."\n";
    echo sprintf("%-6s | %s\n", 'Fila', 'Error');
    echo str_repeat('-', 60)."\n";

    foreach ($erroresPorFila as $entrada) {
        foreach ($entrada['errores'] as $error) {
            echo sprintf("%-6d | %s\n", $entrada['fila'], $error);
        }
    }

    echo str_repeat('-', 60)."\n";
    echo "\nResultado: HAY ERRORES — corregir el archivo y volver a validar.\n\n";

    return 1;
}

exit(main($argv));
