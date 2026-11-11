---
name: project-tooling
description: "Cómo correr tests, estilo, análisis y artisan (todo en Docker)"
metadata:
  node_type: memory
  type: project
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-11-10T12:12:13.382Z
---

Todo corre en Docker; **nunca usar el PHP del host**.

- Tests: `docker compose exec -T app ./vendor/bin/pest` (o `make test`)
- Estilo: `docker compose exec -T app ./vendor/bin/pint` (o `make pint-fix`)
- Análisis: `docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G` (nivel 8)
- Artisan: `docker compose exec -T app php artisan <comando>` (o `make artisan c="..."`)
- `make ci` corre lo mismo que GitHub Actions; `make lint` = Pint + PHPStan; `make reload` recarga código en workers.

Puertos locales: API/Admin http://localhost:8110 · Mailpit :8055 · PostgreSQL :5442 (user/pass `logistics`) · Valkey :6409.

Claves de firma de etiquetas: `make artisan c="logistics:labels:keygen"` (van al `.env`, nunca al repo).

Skills del proyecto en `.claude/skills/` (VERIFICADO 2026-11-10 — nombres previos `github-pr-flow`/
`logistics-package` NO existían; corregido): `bounded-context` (crear/extender un package DDD+CQRS),
`ci-quality-gates` (pasar los gates de CI del PR en un lote: SonarCloud/composer audit/PHPStan/Pint/Pest —
ver [[ci-quality-gates-playbook]]), `admin-entity-hub` (consolidar admin CRUD→CRM: hubs 360, tucar
recursos al padre filtrados, sin huérfanos ni UUIDs — ver [[admin-crm-phases]]). Ver [[project-rules]].

**Admin / dev (añadido 2026-10-13):**
- Login dev del admin: `ehhuallata@dismac.com.bo` / `Dismac*2026`. Necesita rol Admin (ver [[admin-ui-maps]]); tras `migrate:fresh --seed` re-asignarlo con `assignRole(\App\Enums\Role::Admin)`.
- **Ver agentes/workflows en vivo**: comando `/estado` (y `/agents`) o `bash scripts/agent-status.sh` — lee los journals del runtime (no inventado). Útil cuando `/workflows` no está disponible en el terminal.
- **Build del tema Filament**: copiar vendor a host (`docker compose cp app:/var/www/html/vendor/filament vendor/filament`) y `npm run build` en el host (Node v20). No correr dos `npm install` a la vez.
- Al recrear el container `app` (rebuild de imagen), reiniciar nginx (`docker compose restart nginx`) o da 502 (IP vieja).
- Verificación rápida sin tests (permitida): `docker compose exec -T app php artisan route:list` (boot), `migrate:fresh --seed`, y un script tinker que itere `Filament::getResources()` llamando `::getUrl()` + `::getEloquentQuery()->limit(1)->get()` para cazar errores de nav/query.
- **Zona horaria (configurable, default Bolivia)**: `config/app.php` usa `env('APP_TIMEZONE', 'America/La_Paz')`; `.env`/`.env.example` traen `APP_TIMEZONE=America/La_Paz`. Al migrar a otra empresa/país se cambia ese env (regla pedida por el dueño el 2026-10-13; antes estaba en UTC y las horas no cuadraban con Bolivia). A futuro, timezone por-empresa (multi-tenant) iría vía settings.
- **El dueño LEVANTÓ la regla de no-ejecutar-tests** (2026-10-13): autorizó correr pest + make ci + commitear. DoD verde lograda: pest 514 passed, phpstan 8 limpio, pint limpio. (Ver [[no-ejecutar-tests]], ahora obsoleta.)
