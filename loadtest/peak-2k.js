// PEAK 2K — el objetivo de la Ola 5: rampa hasta 2.000 usuarios concurrentes con think time realista
// (5-15 s), meseta y bajada. Mezcla ponderada: choferes (ven su ruta y paradas, mandan GPS, registran
// POD), clientes (rastreo público) y operadores (listados), más un goteo de logins.
//
// Con think de ~10 s, 2.000 VUs son ≈ 200 RPS sostenidos. Para buscar el techo (800-1.000 RPS) bajar el
// think (-e THINK_MIN=1 -e THINK_MAX=3) o subir PEAK_VUS, siempre con un pool acorde (ver README).
//
//   k6 run peak-2k.js
//   k6 run -e PEAK_VUS=2000 -e RAMP_UP=10m -e PLATEAU=20m -e RAMP_DOWN=5m peak-2k.js

import { envNumber } from './lib/config.js';
import { customerSession, driverSession, loginSession, operatorSession } from './lib/flows.js';
import { loginScenario, personaScenarios, preflight, rpmPerVu, split, totalDuration } from './lib/scenarios.js';
import { SUMMARY_TREND_STATS, sloThresholds } from './lib/slo.js';
import { summaryHandler } from './lib/summary.js';

const PEAK_VUS = envNumber('PEAK_VUS', 2000);

const stages = [
    { duration: __ENV.RAMP_UP || '10m', target: PEAK_VUS },
    { duration: __ENV.PLATEAU || '20m', target: PEAK_VUS },
    { duration: __ENV.RAMP_DOWN || '5m', target: 0 },
];

const scenarios = personaScenarios(stages);
scenarios.logins = loginScenario(totalDuration(stages));

export const options = {
    scenarios,
    thresholds: sloThresholds(),
    summaryTrendStats: SUMMARY_TREND_STATS,
    setupTimeout: '60s',
    userAgent: 'k6-loadtest/peak-2k',
};

export function setup() {
    const vus = split(PEAK_VUS);
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

export const handleSummary = summaryHandler('peak-2k');
