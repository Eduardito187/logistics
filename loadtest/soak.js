// SOAK — 1 a 2 horas a carga media para encontrar lo que solo aparece con el tiempo: fugas de memoria,
// conexiones que no se devuelven, tablas/colas que crecen sin freno. Es la prueba CLAVE antes de pasar a
// Octane (el worker vive entre requests: lo que en FPM se libera solo, ahí se acumula).
//
// Conviene bajar la proporción de POD para que el pool de paradas dure la corrida entera:
//
//   k6 run -e POD_RATIO=0.02 soak.js
//   k6 run -e SOAK_VUS=600 -e SOAK_DURATION=2h -e POD_RATIO=0.02 soak.js

import { envNumber } from './lib/config.js';
import { customerSession, driverSession, loginSession, operatorSession } from './lib/flows.js';
import { loginScenario, personaScenarios, preflight, rpmPerVu, split, totalDuration } from './lib/scenarios.js';
import { SUMMARY_TREND_STATS, sloThresholds } from './lib/slo.js';
import { summaryHandler } from './lib/summary.js';

const SOAK_VUS = envNumber('SOAK_VUS', 600);

const stages = [
    { duration: __ENV.SOAK_RAMP || '5m', target: SOAK_VUS },
    { duration: __ENV.SOAK_DURATION || '2h', target: SOAK_VUS },
    { duration: '2m', target: 0 },
];

const scenarios = personaScenarios(stages);
scenarios.logins = loginScenario(totalDuration(stages));

export const options = {
    scenarios,
    thresholds: sloThresholds(),
    summaryTrendStats: SUMMARY_TREND_STATS,
    setupTimeout: '60s',
    userAgent: 'k6-loadtest/soak',
};

export function setup() {
    const vus = split(SOAK_VUS);
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

export const handleSummary = summaryHandler('soak');
