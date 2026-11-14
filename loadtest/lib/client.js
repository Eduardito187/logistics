// Cliente HTTP común: arma las cabeceras que exigen las puertas de la API y etiqueta cada request para
// separar métricas (kind = read | write | tracking | auth | setup; ep = endpoint; persona = quién llama).

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';
import { API, APP_BUILD, BASE_URL, APP_PLATFORM, APP_VERSION, THINK_MAX, THINK_MIN } from './config.js';
import { meta } from './pool.js';

// 429 del limiter: si aparece, el resultado mide el throttle y no la capacidad (ver README, «Rate limit»).
export const rateLimited = new Counter('lt_rate_limited');
// Disponibilidad como la define docs/slo.md: solo 5xx y errores de red cuentan como fallo. Un 4xx es un
// error del cliente (del script o del pool): no entra en http_req_failed, pero hace fallar su check y
// se cuenta aparte en lt_client_errors.
http.setResponseCallback(http.expectedStatuses({ min: 200, max: 499 }));

export const serverErrors = new Counter('lt_server_errors');
export const clientErrors = new Counter('lt_client_errors');
// POD que cerró una parada pendiente vs. POD repetido sobre una parada ya entregada (no-op en el dominio).
export const podFresh = new Counter('lt_pod_fresh');
export const podReplay = new Counter('lt_pod_replay');

// UUID v4 sin dependencias (clave de idempotencia y fix_id de las lecturas GPS).
export function uuidv4() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = Math.floor(Math.random() * 16);

        return (c === 'x' ? r : (r % 4) + 8).toString(16);
    });
}

// Pausa entre pantallas. Con THINK_MAX=0 no duerme (escenarios de tasa fija).
export function think(min = THINK_MIN, max = THINK_MAX) {
    if (max <= 0) {
        return;
    }
    sleep(min + Math.random() * Math.max(0, max - min));
}

function track(res, tags) {
    if (res.status === 429) {
        rateLimited.add(1, tags);
    }
    if (res.status >= 500 || res.status === 0) {
        serverErrors.add(1, tags);
    } else if (res.status >= 400 && res.status !== 429) {
        clientErrors.add(1, tags);
    }

    return res;
}

function appHeaders(token, write) {
    const headers = {
        Accept: 'application/json',
        'X-App-Version': APP_VERSION,
        'X-App-Build': APP_BUILD,
        'X-App-Platform': APP_PLATFORM,
        'X-Device-Model': 'k6-loadtest',
        'X-Device-Os-Version': 'k6',
    };

    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }
    if (write) {
        headers['Content-Type'] = 'application/json';
        // Clave NUEVA por escritura: cada POST es una operación distinta (no un reintento), así el
        // middleware de idempotencia la ejecuta de verdad y la prueba es repetible.
        headers['Idempotency-Key'] = `lt-${uuidv4()}`;
    }

    return headers;
}

// GET de la puerta app (/api/v1/app/*) con token Sanctum ligado a dispositivo.
export function appGet(path, token, tags, extraHeaders) {
    const headers = Object.assign(appHeaders(token, false), extraHeaders || {});

    return track(http.get(`${API}/app${path}`, { headers, tags }), tags);
}

// POST de la puerta app con cuerpo JSON y clave de idempotencia nueva.
export function appPost(path, token, body, tags) {
    const payload = body === null || body === undefined ? null : JSON.stringify(body);

    return track(http.post(`${API}/app${path}`, payload, { headers: appHeaders(token, true), tags }), tags);
}

// GET de la puerta channels (/api/v1/channels/*) con las credenciales del canal del pool.
export function channelGet(path, tags, extraHeaders) {
    const headers = Object.assign(
        {
            Accept: 'application/json',
            'X-Channel-Key': meta.channel.key,
            'X-Channel-Secret': meta.channel.secret,
        },
        extraHeaders || {},
    );

    return track(http.get(`${API}/channels${path}`, { headers, tags }), tags);
}

// GET de una página web pública (sin credenciales): /t/{token}, /admin/login.
export function webGet(path, tags) {
    return track(http.get(`${BASE_URL}${path}`, { headers: { Accept: 'text/html' }, tags }), tags);
}

// Health público (sin credenciales). Limitado a 60/min por IP: usarlo solo en setup/smoke.
export function health(tags) {
    return track(http.get(`${API}/health`, { headers: { Accept: 'application/json' }, tags }), tags);
}

// check() con las etiquetas del request, y devuelve si pasó.
export function expectStatus(res, label, statuses, tags) {
    const allowed = Array.isArray(statuses) ? statuses : [statuses];
    const checks = {};
    checks[label] = (r) => allowed.indexOf(r.status) !== -1;

    return check(res, checks, tags);
}

// `data` del sobre JSON de la API, o null si el cuerpo no es JSON o no trae `data`.
export function dataOf(res) {
    try {
        const body = res.json();

        return body && body.data !== undefined ? body.data : null;
    } catch (_) {
        return null;
    }
}

export function isoNow(offsetSeconds = 0) {
    return new Date(Date.now() + offsetSeconds * 1000).toISOString();
}
