// Flujos de usuario del load test. Cada request lleva tags { kind, ep, persona } para medirlo aparte.
//
// Hay dos familias:
//  - SESIONES (driverSession, customerSession, operatorSession, loginSession): varias pantallas con think
//    time, para los escenarios de usuarios concurrentes (peak, spike, soak, smoke).
//  - REQUESTS SUELTOS (single*): una iteración = un request, para sostener una tasa fija (baseline).
//
// Rutas verificadas contra routes/api*.php y openapi/v1.yaml.

import { group } from 'k6';
import exec from 'k6/execution';
import { PING_BATCH, POD_RATIO } from './config.js';
import {
    appGet,
    appPost,
    channelGet,
    dataOf,
    expectStatus,
    isoNow,
    podFresh,
    podReplay,
    think,
    uuidv4,
    webGet,
} from './client.js';
import {
    loginUsers,
    meta,
    randomDriver,
    randomOperator,
    randomTrackingToken,
    stopAt,
    totalStops,
    vuDriver,
    vuOperator,
} from './pool.js';

const DRIVER = 'driver';
const CUSTOMER = 'customer';
const OPERATOR = 'operator';
const LOGIN = 'login';

function tags(kind, ep, persona) {
    return { kind, ep, persona };
}

// Santa Cruz de la Sierra: posición por defecto cuando la parada no trae coordenadas.
const DEFAULT_LAT = -17.7833;
const DEFAULT_LNG = -63.1821;

function jitter(value) {
    return Math.round((value + (Math.random() - 0.5) * 0.002) * 1e7) / 1e7;
}

// ───────────────────────────── Chofer ─────────────────────────────

// GET /app/vehicles/{vehicleId}/route — «Mi ruta» (sin fecha: la ruta abierta vigente del camión).
export function getRoute(driver) {
    const t = tags('read', 'driver_route', DRIVER);
    const res = appGet(`/vehicles/${driver.vehicle_id}/route`, driver.token, t);
    expectStatus(res, 'driver route 200', 200, t);

    return res.status === 200 ? dataOf(res) : null;
}

// GET /app/vehicles/{vehicleId}/deliveries — hoja de entregas del camión.
export function getVehicleDeliveries(driver) {
    const t = tags('read', 'driver_deliveries', DRIVER);
    const res = appGet(`/vehicles/${driver.vehicle_id}/deliveries`, driver.token, t);
    expectStatus(res, 'vehicle deliveries 200', 200, t);
}

// GET /app/deliveries/{shipmentId} — detalle/estado de la entrega de una parada.
export function getDeliveryState(driver, shipmentId) {
    const t = tags('read', 'delivery_state', DRIVER);
    const res = appGet(`/deliveries/${shipmentId}`, driver.token, t);
    expectStatus(res, 'delivery state 200', 200, t);
}

// POST /app/telemetry/pings — lote de lecturas GPS (202; 207 si hubo rechazos parciales).
export function postPings(driver, lat, lng) {
    const baseLat = typeof lat === 'number' ? lat : DEFAULT_LAT;
    const baseLng = typeof lng === 'number' ? lng : DEFAULT_LNG;
    const pings = [];

    for (let i = PING_BATCH - 1; i >= 0; i--) {
        pings.push({
            lat: jitter(baseLat),
            lng: jitter(baseLng),
            recorded_at: isoNow(-2 * i),
            speed_kph: Math.round(Math.random() * 400) / 10,
            heading: Math.floor(Math.random() * 360),
            accuracy_m: 6,
            ignition: true,
            fix_id: uuidv4(),
        });
    }

    const t = tags('write', 'telemetry_pings', DRIVER);
    const res = appPost(
        '/telemetry/pings',
        driver.token,
        { vehicle_id: driver.vehicle_id, driver_id: driver.driver_id, pings },
        t,
    );
    expectStatus(res, 'telemetry pings accepted', [202, 207], t);
}

// POST /app/deliveries/{shipmentId}/arrived — llegada a la parada (la primera gana; idempotente).
export function postArrived(driver, shipmentId, lat, lng) {
    const t = tags('write', 'stop_arrived', DRIVER);
    const res = appPost(
        `/deliveries/${shipmentId}/arrived`,
        driver.token,
        {
            occurred_at: isoNow(),
            source: 'manual',
            lat: jitter(typeof lat === 'number' ? lat : DEFAULT_LAT),
            lng: jitter(typeof lng === 'number' ? lng : DEFAULT_LNG),
            accuracy_m: 8,
        },
        t,
    );
    expectStatus(res, 'stop arrived 200', 200, t);
}

// POST /app/deliveries/{shipmentId}/complete — POD del envío (unit_ids, una confirmación, GPS). Sin foto:
// el contrato la admite asíncrona («pendiente de foto»), así la prueba no depende del almacenamiento.
export function postPod(driver, shipmentId, unitIds, lat, lng) {
    const t = tags('write', 'pod_complete', DRIVER);
    const res = appPost(
        `/deliveries/${shipmentId}/complete`,
        driver.token,
        {
            unit_ids: unitIds,
            confirmation: `LT-K6-${uuidv4().slice(0, 8)}`,
            lat: jitter(typeof lat === 'number' ? lat : DEFAULT_LAT),
            lng: jitter(typeof lng === 'number' ? lng : DEFAULT_LNG),
            occurred_at: isoNow(),
        },
        t,
    );

    return expectStatus(res, 'proof of delivery 200', 200, t);
}

function poolStop(driver, shipmentId) {
    for (let i = 0; i < driver.stops.length; i++) {
        if (driver.stops[i].shipment_id === shipmentId) {
            return driver.stops[i];
        }
    }

    return null;
}

function unitIdsOf(driver, routeStop) {
    if (Array.isArray(routeStop.unit_ids) && routeStop.unit_ids.length > 0) {
        return routeStop.unit_ids;
    }
    const seeded = poolStop(driver, routeStop.shipment_id);

    return seeded ? seeded.unit_ids : [];
}

// Cierra la parada ACTUAL de la ruta (llegada + POD). Si la ruta ya no tiene pendientes (pool de paradas
// agotado), repite el POD sobre una parada ya entregada: el dominio lo trata como no-op exitoso, así la
// escritura sigue pasando por la puerta, la idempotencia y la transacción, y se cuenta aparte.
export function deliverCurrentStop(driver, route) {
    const current = route && route.current_stop ? route.current_stop : null;

    if (current !== null) {
        const unitIds = unitIdsOf(driver, current);
        if (unitIds.length > 0) {
            postArrived(driver, current.shipment_id, current.lat, current.lng);
            think(1, 3);
            if (postPod(driver, current.shipment_id, unitIds, current.lat, current.lng)) {
                podFresh.add(1);
            }

            return;
        }
    }

    const replay = driver.stops[Math.floor(Math.random() * driver.stops.length)];
    if (postPod(driver, replay.shipment_id, replay.unit_ids)) {
        podReplay.add(1);
    }
}

// Sesión de chofer: abre «Mi ruta», manda su lote de GPS y, según el sorteo, entrega la parada actual,
// mira el detalle de una parada o repasa la hoja de entregas.
export function driverSession() {
    const driver = vuDriver();

    group('driver', () => {
        const route = getRoute(driver);
        const current = route && route.current_stop ? route.current_stop : null;
        think();

        postPings(driver, current ? current.lat : undefined, current ? current.lng : undefined);
        think();

        const roll = Math.random();
        if (roll < POD_RATIO) {
            deliverCurrentStop(driver, route);
        } else if (roll < POD_RATIO + 0.55) {
            const target = current || driver.stops[Math.floor(Math.random() * driver.stops.length)];
            getDeliveryState(driver, target.shipment_id);
        } else {
            getVehicleDeliveries(driver);
        }
        think();
    });
}

// ───────────────────────────── Cliente (rastreo público) ─────────────────────────────

let customerToken = '';
let customerEtag = '';
let customerPolls = 0;

// GET /channels/tracking/{token} — rastreo público por token opaco. Con ETag conocido manda
// If-None-Match (el cliente real pollea): 304 es una respuesta correcta.
export function getPublicTracking(token, etag) {
    const t = tags('tracking', 'tracking_public', CUSTOMER);
    const res = channelGet(`/tracking/${token}`, t, etag ? { 'If-None-Match': etag } : undefined);
    expectStatus(res, 'public tracking 200/304', [200, 304], t);

    return res.status === 200 ? res.headers.Etag || res.headers.ETag || '' : etag;
}

// GET /t/{token} — la PÁGINA pública de rastreo (HTML, sin credenciales): es lo que abre el cliente final
// desde el enlace y lo que el servidor mide como gate="public".
export function getTrackingPage(token) {
    const t = tags('tracking', 'tracking_page', CUSTOMER);
    expectStatus(webGet(`/t/${token}`, t), 'tracking page 200', 200, t);
}

// GET /admin/login — página de acceso del panel Filament (HTML, sin sesión). Es una muestra PARCIAL del
// admin: mide el arranque del panel, no las tablas ni los widgets (ver README, «No cubierto»).
export function getAdminLoginPage() {
    const t = tags('admin', 'admin_login_page', OPERATOR);
    expectStatus(webGet('/admin/login', t), 'admin login page 200', 200, t);
}

// GET /channels/tracking/{token}/units — detalle por producto del rastreo público.
export function getPublicTrackingUnits(token) {
    const t = tags('tracking', 'tracking_public_units', CUSTOMER);
    const res = channelGet(`/tracking/${token}/units`, t);
    expectStatus(res, 'public tracking units 200', 200, t);
}

// Sesión de cliente: sigue SU pedido (mismo token por varias consultas, como quien deja la página abierta).
// La mayoría abre la página /t/{token}; el resto son las consultas JSON de los canales (app de clientes,
// web, call center) con su detalle por producto. Cambia de pedido cada 20 consultas.
export function customerSession() {
    if (customerToken === '' || customerPolls >= 20) {
        customerToken = randomTrackingToken();
        customerEtag = '';
        customerPolls = 0;
    }
    if (customerToken === '') {
        return;
    }

    group('customer', () => {
        customerPolls++;
        if (Math.random() < 0.6) {
            getTrackingPage(customerToken);
        } else {
            customerEtag = getPublicTracking(customerToken, customerEtag);
            if (Math.random() < 0.3) {
                think(1, 3);
                getPublicTrackingUnits(customerToken);
            }
        }
        think();
    });
}

// ───────────────────────────── Operador (listados de oficina) ─────────────────────────────

let fleetSince = '';

// GET /app/shipments — listado paginado (page/per_page).
export function opShipmentsList(operator) {
    const t = tags('read', 'ops_shipments_list', OPERATOR);
    expectStatus(appGet('/shipments?page=1&per_page=25', operator.token, t), 'shipments list 200', 200, t);
}

// GET /app/shipments?status= — listado filtrado por estado.
export function opShipmentsByStatus(operator) {
    const t = tags('read', 'ops_shipments_list', OPERATOR);
    expectStatus(
        appGet('/shipments?status=received&page=1&per_page=25', operator.token, t),
        'shipments list by status 200',
        200,
        t,
    );
}

// GET /app/shipments/summary — resumen del tablero.
export function opShipmentsSummary(operator) {
    const t = tags('read', 'ops_shipments_summary', OPERATOR);
    expectStatus(appGet('/shipments/summary', operator.token, t), 'shipments summary 200', 200, t);
}

// GET /app/telemetry/fleet/live — flota en vivo (?since= con la hora de la consulta anterior).
export function opFleetLive(operator) {
    const t = tags('read', 'ops_fleet_live', OPERATOR);
    const query = fleetSince ? `?since=${encodeURIComponent(fleetSince)}` : '';
    expectStatus(appGet(`/telemetry/fleet/live${query}`, operator.token, t), 'fleet live 200/304', [200, 304], t);
    fleetSince = isoNow();
}

// GET /app/kpis/daily — KPIs operativos del día.
export function opKpisDaily(operator) {
    const t = tags('read', 'ops_kpis_daily', OPERATOR);
    expectStatus(appGet('/kpis/daily', operator.token, t), 'daily kpis 200', 200, t);
}

// GET /app/tracking/shipments/{shipmentId} — seguimiento interno de un envío del pool.
export function opShipmentTracking(operator) {
    const driver = randomDriver();
    const stop = driver.stops[Math.floor(Math.random() * driver.stops.length)];
    const t = tags('read', 'ops_shipment_tracking', OPERATOR);
    expectStatus(appGet(`/tracking/shipments/${stop.shipment_id}`, operator.token, t), 'shipment tracking 200', 200, t);
}

// Listados de oficina con su peso en la mezcla (suman 1).
const OPERATOR_CALLS = [
    [0.25, opShipmentsList],
    [0.15, opShipmentsByStatus],
    [0.2, opShipmentsSummary],
    [0.2, opFleetLive],
    [0.1, opKpisDaily],
    [0.05, opShipmentTracking],
    [0.05, getAdminLoginPage],
];

// Un listado de oficina al azar, ponderado.
export function operatorRequest(operator) {
    let roll = Math.random();

    for (let i = 0; i < OPERATOR_CALLS.length; i++) {
        roll -= OPERATOR_CALLS[i][0];
        if (roll < 0) {
            OPERATOR_CALLS[i][1](operator);

            return;
        }
    }
    opShipmentsList(operator);
}

// Recorre cada listado una vez (smoke).
export function operatorAll(operator) {
    OPERATOR_CALLS.forEach((call) => call[1](operator));
}

export function operatorSession() {
    const operator = vuOperator();

    group('operator', () => {
        operatorRequest(operator);
        think();
    });
}

// ───────────────────────────── Login ─────────────────────────────

// Login → perfil → logout con una cuenta RESERVADA para este flujo (el login rota el token del
// dispositivo, así que nunca se usa una cuenta cuyo token esté usando otro escenario). El login está
// limitado a 10/min por IP: el escenario lo corre a tasa fija por debajo de ese tope.
export function loginSession() {
    if (loginUsers.length === 0) {
        return;
    }
    const account = loginUsers[exec.scenario.iterationInTest % loginUsers.length];

    group('login', () => {
        const loginTags = tags('auth', 'auth_login', LOGIN);
        const res = appPost(
            '/auth/login',
            null,
            {
                email: account.email,
                password: meta.password,
                device: { fingerprint: account.device_fingerprint, platform: 'android', name: 'LT k6 login' },
            },
            loginTags,
        );

        let token = '';
        try {
            token = res.status === 200 ? res.json('token') || '' : '';
        } catch (_) {
            token = '';
        }
        if (!expectStatus(res, 'login 200', 200, loginTags) || token === '') {
            return;
        }

        const meTags = tags('auth', 'auth_me', LOGIN);
        expectStatus(appGet('/auth/me', token, meTags), 'auth me 200', 200, meTags);

        const logoutTags = tags('auth', 'auth_logout', LOGIN);
        expectStatus(appPost('/auth/logout', token, null, logoutTags), 'logout 200', 200, logoutTags);
    });
}

// ───────────────────────────── Requests sueltos (tasa fija) ─────────────────────────────

export function singleRoute() {
    getRoute(randomDriver());
}

export function singleDeliveryState() {
    const driver = randomDriver();
    getDeliveryState(driver, driver.stops[Math.floor(Math.random() * driver.stops.length)].shipment_id);
}

export function singlePings() {
    postPings(randomDriver());
}

// Recorre el pool de paradas EN ORDEN: cada POD cierra una parada distinta hasta agotar el pool.
export function singlePod() {
    const index = exec.scenario.iterationInTest;
    const { driver, stop } = stopAt(index);
    if (postPod(driver, stop.shipment_id, stop.unit_ids)) {
        // Pasada la primera vuelta al pool, las paradas ya están entregadas: el POD es un no-op.
        (index < totalStops ? podFresh : podReplay).add(1);
    }
}

export function singleTracking() {
    const token = randomTrackingToken();
    if (token === '') {
        return;
    }
    if (Math.random() < 0.6) {
        getTrackingPage(token);
    } else {
        getPublicTracking(token, '');
    }
}

export function singleOperator() {
    operatorRequest(randomOperator());
}
