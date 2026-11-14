// SLOs iniciales (docs/slo.md) expresados como thresholds de k6. Son el criterio de aprobación de cada
// corrida: si un threshold falla, k6 termina con código de salida distinto de cero.
//
//   - Disponibilidad 99,9 %            → http_req_failed < 0,1 % (solo 5xx y errores de red; un 4xx es
//                                         error del cliente y no cuenta, igual que en el SLI del servidor)
//   - Lectura app p95 < 250 ms         → kind:read
//   - Escritura / POD p95 < 1 s        → kind:write
//   - Rastreo público p95 < 500 ms     → kind:tracking
//   - Admin p95 < 2,5 s                → kind:admin
//
// El login (kind:auth) no tiene SLO propio: lleva un umbral PROVISIONAL solo para que su p95 salga en el
// resumen (bcrypt lo hace caro a propósito).

import { envNumber } from './config.js';

export const SLO = {
    availabilityErrorRate: envNumber('SLO_ERROR_RATE', 0.001),
    readP95: envNumber('SLO_READ_P95_MS', 250),
    writeP95: envNumber('SLO_WRITE_P95_MS', 1000),
    trackingP95: envNumber('SLO_TRACKING_P95_MS', 500),
    adminP95: envNumber('SLO_ADMIN_P95_MS', 2500),
    authP95Provisional: envNumber('SLO_AUTH_P95_MS', 1500),
};

// Endpoint (tag `ep`) → tipo (tag `kind`). Cada uno recibe el umbral de su tipo para que el resumen
// muestre el p95 por endpoint y se vea cuál es el que rompe el SLO.
export const ENDPOINTS = {
    driver_route: 'read',
    driver_deliveries: 'read',
    delivery_state: 'read',
    ops_shipments_list: 'read',
    ops_shipments_summary: 'read',
    ops_fleet_live: 'read',
    ops_kpis_daily: 'read',
    ops_shipment_tracking: 'read',
    telemetry_pings: 'write',
    stop_arrived: 'write',
    pod_complete: 'write',
    tracking_page: 'tracking',
    tracking_public: 'tracking',
    tracking_public_units: 'tracking',
    admin_login_page: 'admin',
};

function limitOf(kind) {
    if (kind === 'write') {
        return SLO.writeP95;
    }
    if (kind === 'admin') {
        return SLO.adminP95;
    }

    return kind === 'tracking' ? SLO.trackingP95 : SLO.readP95;
}

// Thresholds de una corrida. `extra` permite sumar o pisar umbrales por escenario.
export function sloThresholds(extra) {
    const thresholds = {
        http_req_failed: [`rate<${SLO.availabilityErrorRate}`],
        checks: ['rate>0.999'],
        'http_req_duration{kind:read}': [`p(95)<${SLO.readP95}`],
        'http_req_duration{kind:write}': [`p(95)<${SLO.writeP95}`],
        'http_req_duration{kind:tracking}': [`p(95)<${SLO.trackingP95}`],
        'http_req_duration{kind:admin}': [`p(95)<${SLO.adminP95}`],
        'http_req_duration{kind:auth}': [`p(95)<${SLO.authP95Provisional}`],
        // Un solo 429 invalida la corrida como medición de capacidad: se estaría midiendo el throttle.
        lt_rate_limited: ['count<1'],
    };

    Object.keys(ENDPOINTS).forEach((ep) => {
        thresholds[`http_req_duration{ep:${ep}}`] = [`p(95)<${limitOf(ENDPOINTS[ep])}`];
    });

    return Object.assign(thresholds, extra || {});
}

export const SUMMARY_TREND_STATS = ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'];
