// SPIKE — salto brusco: carga base, subida casi instantánea al pico, meseta corta y vuelta a la base.
// Lo que se mira no es solo el p95 durante el pico sino la RECUPERACIÓN: que al volver a la base la
// latencia y los errores vuelvan a su nivel, sin colas atascadas ni conexiones colgadas.
//
//   k6 run spike.js
//   k6 run -e SPIKE_BASE_VUS=100 -e SPIKE_VUS=2000 -e SPIKE_RISE=20s -e SPIKE_HOLD=3m spike.js

import { envNumber } from './lib/config.js';
import { customerSession, driverSession, loginSession, operatorSession } from './lib/flows.js';
import { loginScenario, personaScenarios, preflight, rpmPerVu, split, totalDuration } from './lib/scenarios.js';
import { SUMMARY_TREND_STATS, sloThresholds } from './lib/slo.js';
import { summaryHandler } from './lib/summary.js';

const BASE_VUS = envNumber('SPIKE_BASE_VUS', 100);
const SPIKE_VUS = envNumber('SPIKE_VUS', 2000);
const RISE = __ENV.SPIKE_RISE || '20s';

const stages = [
    { duration: __ENV.SPIKE_WARMUP || '2m', target: BASE_VUS },
    { duration: RISE, target: SPIKE_VUS },
    { duration: __ENV.SPIKE_HOLD || '3m', target: SPIKE_VUS },
    { duration: RISE, target: BASE_VUS },
    { duration: __ENV.SPIKE_RECOVERY || '3m', target: BASE_VUS },
    { duration: '30s', target: 0 },
];

const scenarios = personaScenarios(stages, BASE_VUS);
scenarios.logins = loginScenario(totalDuration(stages));

export const options = {
    scenarios,
    thresholds: sloThresholds(),
    summaryTrendStats: SUMMARY_TREND_STATS,
    setupTimeout: '60s',
    userAgent: 'k6-loadtest/spike',
};

export function setup() {
    const vus = split(SPIKE_VUS);
    preflight({
        driverRpm: vus.driver * rpmPerVu(),
        operatorRpm: vus.operator * rpmPerVu(),
        driverVUs: vus.driver,
    });
}

export function driver() {
    driverSession();
}

export function customer() {
    customerSession();
}

export function operator() {
    operatorSession();
}

export function login() {
    loginSession();
}

export const handleSummary = summaryHandler('spike');
