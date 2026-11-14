// Configuración común de los escenarios k6 del load test (Ola 5). Solo módulos built-in de k6: nada de
// imports por URL (regla CERO CDN). Cada valor se puede pisar por variable de entorno (-e NOMBRE=valor).

function envNumber(name, fallback) {
    const raw = __ENV[name];
    const parsed = raw === undefined || raw === '' ? NaN : Number(raw);

    return Number.isFinite(parsed) ? parsed : fallback;
}

// URL base del stack bajo prueba (nginx del compose por defecto). Sin barra final.
export const BASE_URL = (__ENV.BASE_URL || 'http://nginx').replace(/\/+$/, '');
export const API = `${BASE_URL}/api/v1`;

// Cabeceras de cliente que lee la puerta app (EnsureAppVersion / EnsureDeviceToken). La versión es alta a
// propósito para no chocar con una mínima configurada (426 upgrade_required).
export const APP_VERSION = __ENV.APP_VERSION || '99.0.0';
export const APP_BUILD = __ENV.APP_BUILD || '9900';
export const APP_PLATFORM = __ENV.APP_PLATFORM || 'android';

// Think time realista entre pantallas (segundos). Los escenarios cerrados (peak/spike/soak) lo usan.
export const THINK_MIN = envNumber('THINK_MIN', 5);
export const THINK_MAX = envNumber('THINK_MAX', 15);

// Proporción de iteraciones de chofer que cierran una parada (llegada + POD). El resto son lecturas.
export const POD_RATIO = envNumber('POD_RATIO', 0.1);

// Lecturas GPS por lote de telemetría.
export const PING_BATCH = envNumber('PING_BATCH', 5);

// Tope del limiter `api` por token en la puerta app (AuthServiceProvider) y margen de seguridad que usa
// el preflight: se exige que el promedio por token quede en un tercio del tope.
export const APP_TOKEN_LIMIT_PER_MIN = 120;
export const TOKEN_SAFE_RPM = envNumber('TOKEN_SAFE_RPM', 40);

// Tope del login por IP (routes/api/auth.php → throttle:10,1). El flujo de login va por debajo.
export const LOGIN_RATE_PER_MIN = Math.min(envNumber('LOGIN_RATE_PER_MIN', 6), 9);

// Carpeta (relativa al directorio de trabajo de k6) donde se exporta el resumen JSON.
export const SUMMARY_DIR = (__ENV.SUMMARY_DIR || 'data/results').replace(/\/+$/, '');
export const RUN_ID = __ENV.RUN_ID || '';

export { envNumber };
