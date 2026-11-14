// Resumen de fin de corrida: texto corto por consola (contra los SLOs) + el resumen completo de k6
// exportado a JSON en SUMMARY_DIR, para comparar corridas (FPM vs Octane) y archivar el reporte.
//
// Definir handleSummary reemplaza el resumen por defecto de k6; por eso acá se arma uno propio, sin
// depender de librerías remotas.

import { BASE_URL, RUN_ID, SUMMARY_DIR } from './config.js';
import { ENDPOINTS, SLO } from './slo.js';

function fmtMs(value) {
    return value === undefined || value === null ? '     n/a' : `${value.toFixed(1).padStart(8)} ms`;
}

function metricValues(data, name) {
    const metric = data.metrics[name];

    return metric && metric.values ? metric.values : null;
}

function thresholdRows(data) {
    const rows = [];
    Object.keys(data.metrics)
        .sort()
        .forEach((name) => {
            const thresholds = data.metrics[name].thresholds;
            if (!thresholds) {
                return;
            }
            Object.keys(thresholds).forEach((expression) => {
                rows.push({ name, expression, ok: thresholds[expression].ok === true });
            });
        });

    return rows;
}

function durationLine(data, label, metricName) {
    const values = metricValues(data, metricName);
    if (values === null || values['p(95)'] === undefined) {
        return `  ${label.padEnd(28)} sin muestras`;
    }

    return `  ${label.padEnd(28)} p95 ${fmtMs(values['p(95)'])}   p99 ${fmtMs(values['p(99)'])}   med ${fmtMs(values.med)}`;
}

function textSummary(data, scenario) {
    const lines = [];
    const reqs = metricValues(data, 'http_reqs');
    const failed = metricValues(data, 'http_req_failed');
    const checks = metricValues(data, 'checks');
    const limited = metricValues(data, 'lt_rate_limited');
    const errors = metricValues(data, 'lt_server_errors');
    const client = metricValues(data, 'lt_client_errors');
    const fresh = metricValues(data, 'lt_pod_fresh');
    const replay = metricValues(data, 'lt_pod_replay');
    const vus = metricValues(data, 'vus_max');

    lines.push('');
    lines.push(`=== Load test «${scenario}» contra ${BASE_URL} ===`);
    lines.push(`  duración          ${(data.state.testRunDurationMs / 1000).toFixed(0)} s`);
    lines.push(`  VUs máx           ${vus ? vus.max || vus.value : 'n/a'}`);
    lines.push(`  requests          ${reqs ? reqs.count : 0}  (${reqs ? reqs.rate.toFixed(1) : '0'} RPS promedio)`);
    lines.push(`  fallos (5xx/red)  ${failed ? (failed.rate * 100).toFixed(3) : '0'} %  (SLO < ${(SLO.availabilityErrorRate * 100).toFixed(1)} %)`);
    lines.push(`  checks OK         ${checks ? (checks.rate * 100).toFixed(3) : 'n/a'} %`);
    lines.push(`  429 (throttle)    ${limited ? limited.count : 0}`);
    lines.push(`  5xx / red         ${errors ? errors.count : 0}`);
    lines.push(`  4xx (no SLO)      ${client ? client.count : 0}`);
    lines.push(`  POD frescos       ${fresh ? fresh.count : 0}   POD repetidos (no-op) ${replay ? replay.count : 0}`);
    lines.push('');
    lines.push(`Latencia por tipo (SLO p95: lectura < ${SLO.readP95} ms, escritura < ${SLO.writeP95} ms, rastreo < ${SLO.trackingP95} ms, admin < ${SLO.adminP95} ms)`);
    lines.push(durationLine(data, 'lectura app', 'http_req_duration{kind:read}'));
    lines.push(durationLine(data, 'escritura / POD', 'http_req_duration{kind:write}'));
    lines.push(durationLine(data, 'rastreo público', 'http_req_duration{kind:tracking}'));
    lines.push(durationLine(data, 'admin (página de acceso)', 'http_req_duration{kind:admin}'));
    lines.push(durationLine(data, 'login (provisional)', 'http_req_duration{kind:auth}'));
    lines.push('');
    lines.push('Latencia por endpoint');
    Object.keys(ENDPOINTS).forEach((ep) => {
        lines.push(durationLine(data, ep, `http_req_duration{ep:${ep}}`));
    });
    lines.push('');
    lines.push('Thresholds (SLOs)');
    let allOk = true;
    thresholdRows(data).forEach((row) => {
        allOk = allOk && row.ok;
        lines.push(`  [${row.ok ? ' OK ' : 'FALLA'}] ${row.name}: ${row.expression}`);
    });
    lines.push('');
    lines.push(allOk ? 'RESULTADO: dentro de los SLOs.' : 'RESULTADO: FUERA de los SLOs (ver las líneas FALLA).');
    lines.push('');

    return lines.join('\n');
}

// Fábrica del handleSummary de cada escenario. El archivo queda en SUMMARY_DIR/<escenario>[-RUN_ID]-<fecha>.json.
export function summaryHandler(scenario) {
    return function handleSummary(data) {
        const stamp = new Date().toISOString().replace(/[:.]/g, '-');
        const runId = RUN_ID ? `-${RUN_ID}` : '';
        const file = `${SUMMARY_DIR}/${scenario}${runId}-${stamp}.json`;
        const text = textSummary(data, scenario);
        const outputs = { stdout: `${text}Resumen JSON: ${file}\n` };

        outputs[file] = JSON.stringify(
            {
                scenario,
                run_id: RUN_ID,
                base_url: BASE_URL,
                finished_at: new Date().toISOString(),
                slo: SLO,
                summary: data,
            },
            null,
            2,
        );

        return outputs;
    };
}
