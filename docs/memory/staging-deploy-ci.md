---
name: staging-deploy-ci
description: "Deploy automático de staging en Oracle Cloud (self-hosted runner) — workflow, compose autónomo, secrets, cómo probarlo local y trampas vistas"
metadata:
  node_type: memory
  type: project
  originSessionId: 5ae2cd97-4f6d-4a3c-8c55-472d9c313fac
  modified: 2026-11-11T14:24:45.740Z
---

Montado el 2026-11-11. Servidor Ubuntu 20.04 en Oracle Cloud con self-hosted runner YA registrado al repo
(labels `self-hosted, docker`, servicio systemd, runner NO efímero, disco 45 GB). Guía: `docs/deploy-staging.md`.

**Piezas:**
- `.github/workflows/staging-deploy.yml`: `push` a `staging` + `workflow_dispatch`; candado
  `if: github.ref == 'refs/heads/staging'` (el dueño pidió explícitamente "deploy SOLO en staging");
  concurrency por rama con cancel-in-progress; limpieza `if: always()` (env con secretos, image prune,
  builder prune >7d, workspace).
- `.github/workflows/staging-pr-check.yml`: PR→staging solo construye (proyecto `logistics-staging-pr-<n>`,
  tag `pr-<n>`), bloquea PRs de forks (código ajeno en el server). Los tests los sigue corriendo `ci.yml`.
- `.docker/compose/docker-compose.staging.yml` AUTÓNOMO (no apilado sobre el de dev: dev monta código, tiene
  credenciales fijas y publica 10 puertos). `name: logistics-staging`; servicios postgres, pgbouncer, valkey,
  app (FPM), worker (supervisord: 4 colas + ÚNICO scheduler), web. SOLO publica `STAGING_HTTP_PORT` (8110).
  Sin bind-mounts (el workspace se borra); PostGIS lo crea el init de la imagen (`10_postgis.sh`).
- Stage nuevo `web` en `.docker/php/Dockerfile`: nginx con `public/` copiado del stage `prod` + symlink storage.
- `scripts/staging/render-env.sh` (env desde secrets; `STAGING_ENV_EXTRA` pisa claves sin duplicar; 600) y
  `scripts/staging/deploy.sh` (preflight → build → infra `--wait` → `migrate --force` en `run --rm` →
  `up -d --build --remove-orphans --wait` → smoke `/up`, `/api/v1/health` status ok, `/admin/login`; trap ERR
  vuelca ps + logs; `set -E` para que el trap aplique dentro de funciones).
- Secrets: `STAGING_APP_KEY`, `STAGING_DB_PASSWORD` (NO cambiarlo tras el 1er deploy: el volumen fija la
  clave), opcional `STAGING_ENV_EXTRA`. Variables: `STAGING_HTTP_PORT`, `STAGING_APP_URL`. Puerto a abrir
  aparte en la Security List de OCI.

**Probado local (Docker Desktop):** `STAGING_PROJECT=logistics-staging-localtest STAGING_IMAGE_TAG=localtest
bash scripts/staging/deploy.sh <env>` con puerto 8199 → 1ª corrida en limpio OK (6 healthy, smoke 200),
2ª idempotente ("Nothing to migrate"), clave mala → falla en 180 s con mensaje. Al terminar
`docker compose -p logistics-staging-localtest down -v` (es un proyecto propio de prueba).

**Trampa vista:** `entrypoint.sh` esperaba a Postgres SIN tope → con credenciales malas el deploy colgaba
(PgBouncer deja intentos colgados). Se agregó `DB_WAIT_TIMEOUT` medido con `$SECONDS` + `timeout 10` por
intento (contar intentos convertía 180 en horas). Default 0 = infinito (dev igual); staging usa 180.
Ver [[ci-docker-vite-gotchas]], [[windows-migration]].

**Sonar PR 27 (2026-11-11):** el stage `web` corría nginx como root → `docker:S6471` bajó el Security
Rating a B y falló el Quality Gate. Ahora `nginxinc/nginx-unprivileged:1.27-alpine` (uid 101, escucha 8080
dentro; el compose publica `STAGING_HTTP_PORT:8080`). Verificado: master y workers como `nginx`, `nginx -t` OK, estáticos 200.
Segundo aviso de Sonar sobre el mismo stage: `COPY --chown=101:101` → "copied resource can be modified by
a non-root user". Corregido: `USER root`, COPY sin chown, `chown root` + 755/644, `USER 101` al final
(uid 101 solo lee; verificado que no puede escribir y que sirve 200).

**Primer merge a staging (PR #28, 49e8bc0):** staging-deploy y staging-pr-check salieron "Failure" con 0
jobs (nombre = ruta del archivo): workflows INVÁLIDOS por `${{ runner.temp }}` en `env:` de job. Arreglado
con un paso `echo "ENV_FILE=$RUNNER_TEMP/..." >> "$GITHUB_ENV"`; validado con actionlint (exit 0,
config `.github/actionlint.yaml` con labels docker/oci). Por merge a staging lo esperado son 2 corridas:
CI + Staging deploy (pr-check solo corre en PRs).
