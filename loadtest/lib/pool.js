// Pool de carga exportado por `php artisan loadtest:prepare --force` (loadtest/data/pool.json).
//
// Se carga con SharedArray: una sola copia en memoria para todos los VUs. Cada acceso a un elemento lo
// copia, por eso cada sección va en su propio arreglo de elementos chicos y los flujos guardan en una
// variable del VU el elemento que usan (ver vuDriver / vuOperator).

import { SharedArray } from 'k6/data';
import exec from 'k6/execution';

// Lee el archivo del pool. Con POOL_FILE se usa esa ruta tal cual (el compose la fija absoluta). Sin
// ella se prueban las dos ubicaciones relativas posibles, porque según la versión de k6 `open()` resuelve
// contra este módulo (lib/) o contra el script de entrada (loadtest/).
function readPool() {
    if (__ENV.POOL_FILE) {
        return open(__ENV.POOL_FILE);
    }

    try {
        return open('../data/pool.json');
    } catch (_) {
        return open('./data/pool.json');
    }
}

function section(name, pick) {
    return new SharedArray(`lt-${name}`, () => {
        const picked = pick(JSON.parse(readPool()));

        return Array.isArray(picked) ? picked : [picked];
    });
}

export const drivers = section('drivers', (pool) => pool.drivers || []);
export const operators = section('operators', (pool) => pool.operators || []);
export const loginUsers = section('login-users', (pool) => pool.login_users || []);

// Datos sueltos del pool (canal del rastreo público, contraseña conocida, lote) en un único elemento.
const metaArray = section('meta', (pool) => ({
    channel: pool.channel || { key: '', secret: '' },
    password: pool.password || 'password123',
    batch: pool.batch || '',
    generated_at: pool.generated_at || '',
}));

export const meta = metaArray[0];

// Paradas por chofer (el comando siembra la misma cantidad para todos).
export const stopsPerDriver = drivers.length > 0 ? drivers[0].stops.length : 0;
export const totalStops = drivers.length * stopsPerDriver;

let cachedDriver = null;
let cachedOperator = null;

// Chofer "dueño" de este VU: estable durante toda la corrida. k6 reparte los VUs de cada escenario en
// bloques contiguos de ids, así que el módulo por el tamaño del pool no repite chofer mientras el pool
// tenga al menos tantos choferes como VUs de chofer.
export function vuDriver() {
    if (cachedDriver === null) {
        cachedDriver = drivers[(exec.vu.idInTest - 1) % drivers.length];
    }

    return cachedDriver;
}

export function vuOperator() {
    if (cachedOperator === null) {
        cachedOperator = operators[(exec.vu.idInTest - 1) % operators.length];
    }

    return cachedOperator;
}

export function randomDriver() {
    return drivers[Math.floor(Math.random() * drivers.length)];
}

export function randomOperator() {
    return operators[Math.floor(Math.random() * operators.length)];
}

// Parada número `index` del pool aplanado (chofer por chofer). Recorrerlas en orden entrega cada parada
// una sola vez antes de repetir: así las escrituras de POD son frescas mientras dure el pool.
export function stopAt(index) {
    const flat = ((index % totalStops) + totalStops) % totalStops;
    const driver = drivers[Math.floor(flat / stopsPerDriver)];

    return { driver, stop: driver.stops[flat % stopsPerDriver] };
}

// Token de rastreo público de una parada al azar (el pedido que "mira" un cliente).
export function randomTrackingToken() {
    for (let attempt = 0; attempt < 5; attempt++) {
        const driver = randomDriver();
        const stop = driver.stops[Math.floor(Math.random() * driver.stops.length)];
        if (stop && stop.tracking_token) {
            return stop.tracking_token;
        }
    }

    return '';
}

// Corta la corrida con un mensaje claro si falta el pool o le faltan piezas.
export function assertPool() {
    if (drivers.length === 0 || stopsPerDriver === 0) {
        exec.test.abort('The load-test pool has no drivers/stops. Run: php artisan loadtest:prepare --force');
    }
    if (operators.length === 0) {
        exec.test.abort('The load-test pool has no operators. Run loadtest:prepare with --operators>=1.');
    }
    if (!meta.channel.key || !meta.channel.secret) {
        exec.test.abort('The load-test pool has no tracking channel credentials. Re-run loadtest:prepare --force.');
    }
}
