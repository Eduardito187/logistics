---
name: staging-deploy
description: >-
  Deploy automático de STAGING en el servidor de Oracle Cloud (self-hosted runner, labels self-hosted+docker):
  jobs `deploy-staging`/`staging-build` de ci.yml, compose autónomo logistics-staging, secrets, smoke test.
  Usar al tocar el Dockerfile/compose/scripts de staging, cuando falle el job "Deploy staging" o
  "Staging build" del CI, al
  agregar un servicio o variable a staging, o para probar el deploy localmente antes de pushear.
---

# Staging deploy (logistics)

Guía para humanos: `docs/deploy-staging.md`. Esta skill es el cómo-trabajarlo.

## Piezas (no romper estos contratos)

| Archivo | Rol |
|---|---|
| `.github/workflows/ci.yml` job `deploy-staging` | push a `staging` (+ manual). `needs: [lint, test, assets, security, image]` → deploy SOLO si el CI pasó; checkout de `github.sha` (el commit validado). Candado `github.event_name != 'pull_request' && github.ref == 'refs/heads/staging'` |
| `.github/workflows/ci.yml` job `staging-build` | PR→staging: solo build (proyecto `logistics-staging-pr-<n>`, tag `pr-<n>`); bloquea forks |

**Una sola corrida por evento.** No crear workflows aparte para staging: el dueño vio "2 corridas por
merge" (CI y deploy en paralelo, el deploy sin esperar a los tests) y pidió unificarlo. Todo va en `ci.yml`.
| `.docker/compose/docker-compose.staging.yml` | AUTÓNOMO (no se apila sobre el de dev). `name: logistics-staging`. Sin bind-mounts (el runner borra el workspace) |
| `.docker/php/Dockerfile` stages `prod` (app/worker) y `web` | `web` = `nginxinc/nginx-unprivileged` (uid 101, escucha **8080**; Sonar S6471 exige no-root) con `public/` horneado |
| `scripts/staging/render-env.sh` | env desde secrets (`STAGING_APP_KEY`, `STAGING_DB_PASSWORD`, opcional `STAGING_ENV_EXTRA` que pisa claves) |
| `scripts/staging/deploy.sh` | preflight → build → infra `--wait` → `migrate --force` (run --rm) → `up -d --build --remove-orphans --wait` → smoke |

Smoke: `/up` 200, `/api/v1/health` 200 con `"status":"ok"`, `/admin/login` 200. Fallo → `trap ERR`
(con `set -E`) vuelca `ps` + últimas 80 líneas de cada servicio y sale ≠0.

## Probar localmente ANTES de pushear (Docker Desktop)

```bash
S=<scratchpad>
APP_KEY="base64:$(head -c32 /dev/urandom | base64)" DB_PASSWORD="LocalTest-$RANDOM" STAGING_HTTP_PORT=8199 \
  bash scripts/staging/render-env.sh $S/staging-local.env
STAGING_PROJECT=logistics-staging-localtest STAGING_IMAGE_TAG=localtest bash scripts/staging/deploy.sh $S/staging-local.env
# correrlo 2 veces (idempotencia: "Nothing to migrate", mismos contenedores)
# limpieza (proyecto PROPIO de prueba, no dev):
docker compose -p logistics-staging-localtest down -v --remove-orphans
docker image rm dismac/logistics-app:localtest dismac/logistics-web:localtest
rm -f $S/staging-local.env
```
Nunca usar el proyecto `dismac-logistics` (dev) ni `logistics-staging` en local. Correr en background y
esperar la notificación (el primer build compila extensiones PHP, varios minutos).

## Agregar algo a staging

- **Variable de app**: default en `render-env.sh` (heredoc base) o que el dueño la ponga en
  `STAGING_ENV_EXTRA`. Documentarla en `docs/deploy-staging.md`.
- **Servicio nuevo**: en el compose de staging, con healthcheck (el `--wait` lo exige), sin `ports` salvo
  que el dueño lo pida (cada puerto hay que abrirlo en la Security List de OCI), sin bind-mounts.
- **Cambio en el Dockerfile**: validar con `docker build --target web|prod` + la prueba local completa.

## Validar los workflows ANTES de pushear (obligatorio)

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/repo" -w /repo rhysd/actionlint:1.7.7 -color=false   # debe salir exit 0
```
Config de etiquetas del runner (`docker`, `oci`) en `.github/actionlint.yaml`. Un workflow inválido NO
falla "en su job": GitHub lo marca fallido con **0 jobs** en CADA push (aparece con la ruta del archivo en
vez del nombre), aunque su disparador no corresponda — así un merge a staging mostró 3 corridas.

## Regla dura: tiene que funcionar en un docker NUEVO

Nada visible puede depender del `.env` local del dueño. Antes de dar algo por arreglado, comparar `.env`
vs `.env.example` / `render-env.sh` y dejar un candado (test o check del smoke). Caso real: el mapa de
flota sin fondo en staging porque el default de teselas era `localhost:8082`; hoy el default es OSM
público, `SecurityHeadersTest` lo protege y el smoke de `deploy.sh` falla si `maps.tile_url` es localhost.

## Trampas ya vistas

- **`${{ runner.temp }}` en `env:` a nivel de job invalida el workflow** (contexto no disponible ahí:
  solo github/inputs/matrix/needs/secrets/strategy/vars). Armar rutas en un paso:
  `echo "ENV_FILE=$RUNNER_TEMP/x.env" >> "$GITHUB_ENV"`. Pasó en el primer merge a staging (2026-11-11).

- `entrypoint.sh` sin tope esperando Postgres → deploy colgado con credenciales malas. Hoy:
  `DB_WAIT_TIMEOUT` medido con `$SECONDS` + `timeout 10` por intento (contar intentos ≠ segundos).
- `STAGING_DB_PASSWORD` no se puede cambiar tras el primer deploy (el volumen fija la clave).
- `docker compose -p logistics-staging logs -f` funciona sin el archivo compose (para operar en el server).
- Un `.sh` con CRLF rompe el contenedor (`bash\r`): `.gitattributes` fuerza LF.

Relacionado: memorias [[staging-deploy-ci]], [[ci-docker-vite-gotchas]]; skill `ci-quality-gates`.
