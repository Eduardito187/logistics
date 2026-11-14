// Piezas comunes para declarar escenarios: mezcla ponderada de personas, etapas de rampa y el preflight
// que valida el pool contra el rate limit por token antes de gastar una corrida.

import exec from 'k6/execution';
import { LOGIN_RATE_PER_MIN, THINK_MAX, THINK_MIN, TOKEN_SAFE_RPM, envNumber } from './config.js';
import { expectStatus, health } from './client.js';
import { assertPool, drivers, loginUsers, operators } from './pool.js';

// Mezcla de personas de los escenarios de usuarios concurrentes (suma 1): choferes en ruta, clientes
// mirando el rastreo público y operadores de oficina en los listados.
export const MIX = {
    driver: envNumber('MIX_DRIVER', 0.6),
    customer: envNumber('MIX_CUSTOMER', 0.3),
    operator: envNumber('MIX_OPERATOR', 0.1),
};

// Reparte un total de VUs según la mezcla (al menos 1 por persona).
export function split(total) {
    return {
        driver: Math.max(1, Math.round(total * MIX.driver)),
        customer: Math.max(1, Math.round(total * MIX.customer)),
        operator: Math.max(1, Math.round(total * MIX.operator)),
    };
}

// Escenarios ramping-vus de las tres personas a partir de una lista de etapas expresadas en VUs TOTALES:
// [{ duration: '10m', target: 2000 }, …]. Cada persona recibe su parte de cada etapa.
export function personaScenarios(totalStages, startVUs = 0, gracefulRampDown = '30s') {
    const scenarios = {};
    const start = split(Math.max(startVUs, 0));

    ['driver', 'customer', 'operator'].forEach((persona) => {
        scenarios[`${persona}s`] = {
            executor: 'ramping-vus',
            exec: persona,
            startVUs: startVUs > 0 ? start[persona] : 0,
            stages: totalStages.map((stage) => ({
                duration: stage.duration,
                target: stage.target > 0 ? split(stage.target)[persona] : 0,
            })),
            gracefulRampDown,
            gracefulStop: '30s',
        };
    });

    return scenarios;
}

// Duración total de una lista de etapas ('30s', '10m', '2h') como texto en segundos ('2100s').
export function totalDuration(stages) {
    const units = { s: 1, m: 60, h: 3600 };
    let seconds = 0;

    stages.forEach((stage) => {
        const match = /^(\d+(?:\.\d+)?)([smh])$/.exec(String(stage.duration).trim());
        if (match === null) {
            throw new Error(`Unsupported duration "${stage.duration}" (use a single unit: 30s, 10m, 2h).`);
        }
        seconds += Number(match[1]) * units[match[2]];
    });

    return `${Math.round(seconds)}s`;
}

// Flujo de login a tasa fija, por debajo del throttle de 10/min por IP. Se suma a cualquier escenario.
export function loginScenario(duration) {
    return {
        executor: 'constant-arrival-rate',
        exec: 'login',
        rate: LOGIN_RATE_PER_MIN,
        timeUnit: '1m',
        duration,
        preAllocatedVUs: 2,
        maxVUs: 5,
    };
}

// Requests por minuto que genera un VU con think time (un request por pausa, en promedio).
export function rpmPerVu() {
    const avgThink = Math.max(0.5, (THINK_MIN + THINK_MAX) / 2);

    return 60 / avgThink;
}

// Preflight (se llama desde setup): el pool existe, el stack responde y hay tokens suficientes para que
// el limiter `api` (120/min POR TOKEN en la puerta app) no contamine la medición. `driverRpm` y
// `operatorRpm` son los requests por minuto TOTALES esperados con tokens de chofer y de operador.
export function preflight({ driverRpm, operatorRpm, driverVUs }) {
    assertPool();

    const res = health({ kind: 'setup', ep: 'health', persona: 'setup' });
    if (!expectStatus(res, 'health 200', 200, { kind: 'setup', ep: 'health', persona: 'setup' })) {
        exec.test.abort(`The stack is not healthy (GET /api/v1/health → ${res.status}). Aborting.`);
    }

    if (__ENV.SKIP_PREFLIGHT === '1') {
        return;
    }

    const neededDrivers = Math.ceil(driverRpm / TOKEN_SAFE_RPM);
    if (drivers.length < neededDrivers) {
        exec.test.abort(
            `Pool too small: ${drivers.length} driver tokens for ~${Math.round(driverRpm)} req/min ` +
                `(need >= ${neededDrivers} to stay under the 120/min per-token limit). ` +
                `Run: php artisan loadtest:prepare --drivers=${neededDrivers} --force`,
        );
    }

    const neededOperators = Math.ceil(operatorRpm / TOKEN_SAFE_RPM);
    if (operators.length < neededOperators) {
        exec.test.abort(
            `Pool too small: ${operators.length} operator tokens for ~${Math.round(operatorRpm)} req/min ` +
                `(need >= ${neededOperators}). Run: php artisan loadtest:prepare --operators=${neededOperators} --force`,
        );
    }

    if (driverVUs && drivers.length < driverVUs) {
        console.warn(
            `Pool has ${drivers.length} drivers for ${driverVUs} driver VUs: some VUs will share a driver ` +
                '(shared token budget and concurrent POD on the same stops).',
        );
    }
    if (loginUsers.length === 0) {
        console.warn('Pool has no login accounts: the login flow will be skipped.');
    }
}
