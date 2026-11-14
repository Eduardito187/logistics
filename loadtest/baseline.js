// BASELINE — carga sostenida a tasa FIJA (~200 RPS por defecto), modelo abierto: k6 dispara los requests
// al ritmo pedido aunque el servidor se ponga lento, así la latencia no "frena" la carga y el resultado
// es comparable entre corridas (FPM vs Octane, antes vs después de un cambio).
//
// Una iteración = un request. La mezcla reparte la tasa entre lecturas de chofer, GPS, POD, rastreo
// público y listados de oficina. El POD recorre el pool de paradas en orden (cada una se entrega una vez).
//
//   k6 run baseline.js
//   k6 run -e BASELINE_RPS=200 -e BASELINE_DURATION=10m baseline.js

import { envNumber } from './lib/config.js';
import {
    loginSession,
    singleDeliveryState,
    singleOperator,
    singlePings,
    singlePod,
    singleRoute,
    singleTracking,
} from './lib/flows.js';
import { loginScenario, preflight, totalDuration } from './lib/scenarios.js';
import { SUMMARY_TREND_STATS, sloThresholds } from './lib/slo.js';
import { summaryHandler } from './lib/summary.js';

const RPS = envNumber('BASELINE_RPS', 200);

// Parte de la tasa total de cada tipo de request (suma 1).
const SHARES = {
    route: 0.34,
    state: 0.1,
    pings: 0.25,
    pod: 0.01,
    tracking: 0.2,
    operator: 0.1,
};

const stages = [
    { duration: __ENV.BASELINE_WARMUP || '1m' },
    { duration: __ENV.BASELINE_DURATION || '10m' },
];

// Tasa por ventanas de 10 s para no perder precisión con las partes chicas (el POD son ~2/s a 200 RPS).
function arrival(exec, share) {
    const perSecond = RPS * share;
    const rate = Math.max(1, Math.round(perSecond * 10));

    return {
        executor: 'ramping-arrival-rate',
        exec,
        startRate: 0,
        timeUnit: '10s',
        stages: [
            { duration: stages[0].duration, target: rate },
            { duration: stages[1].duration, target: rate },
        ],
        // VUs necesarios ≈ tasa × latencia: se reserva para ~0,5 s y se deja crecer si el servidor se demora.
        preAllocatedVUs: Math.max(5, Math.ceil(perSecond * 0.5)),
        maxVUs: Math.max(20, Math.ceil(perSecond * 5)),
    };
}

export const options = {
    scenarios: {
        driver_route: arrival('route', SHARES.route),
        driver_state: arrival('state', SHARES.state),
        driver_pings: arrival('pings', SHARES.pings),
        driver_pod: arrival('pod', SHARES.pod),
        customer_tracking: arrival('tracking', SHARES.tracking),
        operator_lists: arrival('operator', SHARES.operator),
        logins: loginScenario(totalDuration(stages)),
    },
    thresholds: sloThresholds({
        // En modelo abierto, iteraciones descartadas = el generador no dio abasto (faltaron VUs): la tasa
        // real fue menor a la pedida y la corrida no vale como baseline.
        dropped_iterations: ['count<1'],
    }),
    summaryTrendStats: SUMMARY_TREND_STATS,
    setupTimeout: '60s',
    userAgent: 'k6-loadtest/baseline',
};

export function setup() {
    preflight({
        driverRpm: RPS * (SHARES.route + SHARES.state + SHARES.pings + SHARES.pod) * 60,
        operatorRpm: RPS * SHARES.operator * 60,
    });
}

export function route() {
    singleRoute();
}

export function state() {
    singleDeliveryState();
}

export function pings() {
    singlePings();
}

export function pod() {
    singlePod();
}

export function tracking() {
    singleTracking();
}

export function operator() {
    singleOperator();
}

export function login() {
    loginSession();
}

export const handleSummary = summaryHandler('baseline');
