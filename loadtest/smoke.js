// SMOKE — 1 a 5 VUs, una pasada: verifica que el pool sirve y que cada flujo del load test funciona de
// punta a punta ANTES de gastar una corrida larga. No mide capacidad; exige que pasen los checks.
//
// Cada VU recorre: ruta del chofer → GPS → hoja de entregas → detalle de la parada → llegada + POD de la
// parada actual → ruta otra vez (debe haber avanzado) → rastreo público → cada listado de oficina.
// El VU 1 además hace login → perfil → logout (uno solo: el login está limitado a 10/min por IP).
//
//   k6 run smoke.js
//   k6 run -e SMOKE_VUS=3 smoke.js

import { check, sleep } from 'k6';
import exec from 'k6/execution';
import { envNumber } from './lib/config.js';
import {
    deliverCurrentStop,
    getDeliveryState,
    getPublicTracking,
    getPublicTrackingUnits,
    getRoute,
    getTrackingPage,
    getVehicleDeliveries,
    loginSession,
    operatorAll,
    postPings,
} from './lib/flows.js';
import { vuDriver, vuOperator } from './lib/pool.js';
import { preflight } from './lib/scenarios.js';
import { SUMMARY_TREND_STATS, sloThresholds } from './lib/slo.js';
import { summaryHandler } from './lib/summary.js';

const VUS = Math.min(5, Math.max(1, envNumber('SMOKE_VUS', 1)));

export const options = {
    scenarios: {
        smoke: {
            executor: 'per-vu-iterations',
            vus: VUS,
            iterations: 1,
            maxDuration: '5m',
        },
    },
    // Funcional: ni un check fallido. Las latencias se informan, pero con 1-5 VUs no dicen nada de capacidad.
    thresholds: sloThresholds({ checks: ['rate==1'] }),
    summaryTrendStats: SUMMARY_TREND_STATS,
    setupTimeout: '60s',
    userAgent: 'k6-loadtest/smoke',
};

export function setup() {
    preflight({ driverRpm: 0, operatorRpm: 0 });
}

function pause() {
    sleep(0.5 + Math.random());
}

export default function smoke() {
    const driver = vuDriver();
    const operator = vuOperator();

    if (exec.vu.idInTest === 1) {
        loginSession();
        pause();
    }

    // Chofer.
    const before = getRoute(driver);
    check(before, { 'route has stops': (route) => route !== null && Array.isArray(route.stops) && route.stops.length > 0 });
    pause();

    const current = before && before.current_stop ? before.current_stop : null;
    postPings(driver, current ? current.lat : undefined, current ? current.lng : undefined);
    pause();
    getVehicleDeliveries(driver);
    pause();
    getDeliveryState(driver, (current || driver.stops[0]).shipment_id);
    pause();

    deliverCurrentStop(driver, before);
    pause();

    const after = getRoute(driver);
    if (current !== null) {
        // Había parada pendiente: el POD tuvo que cerrarla y avanzar la ruta.
        check(after, {
            'route advanced after POD': (route) =>
                route !== null && route.completed_stops > before.completed_stops,
        });
    }
    pause();

    // Cliente: rastreo público de una parada del propio chofer.
    const token = driver.stops[0].tracking_token;
    check(token, { 'stop has a public tracking token': (value) => typeof value === 'string' && value !== '' });
    if (token) {
        getTrackingPage(token);
        pause();
        const etag = getPublicTracking(token, '');
        pause();
        getPublicTracking(token, etag);
        pause();
        getPublicTrackingUnits(token);
        pause();
    }

    // Operador: cada listado una vez.
    operatorAll(operator);
}

export const handleSummary = summaryHandler('smoke');
