# ============================================================
# Makefile — logistics
# Wrapper de comandos Docker para el equipo de desarrollo
# ============================================================
# Uso:
#   make help        → lista todos los comandos disponibles
#   make install     → primer setup completo
#   make up          → arrancar servicios
#   make shell       → shell dentro del container app
# ============================================================

.DEFAULT_GOAL := help
.PHONY: help install up down restart build rebuild status logs shell root-shell \
        composer artisan tinker migrate fresh seed test test-unit coverage \
        lint pint pint-fix stan ci db-shell valkey-shell reload npm-install npm-dev assets

DC := docker compose
APP := $(DC) exec app
APP_ROOT := $(DC) exec -u root app
NODE := $(DC) run --rm node

help: ## Muestra este mensaje de ayuda
	@echo ""
	@echo "  logistics — comandos disponibles:"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'
	@echo ""

# ------------------------------------------------------------
# Ciclo de vida
# ------------------------------------------------------------
install: ## Setup inicial: build + composer install + key + migrate
	$(DC) build
	$(DC) up -d
	$(APP) composer install
	$(APP) cp -n .env.example .env || true
	$(APP) php artisan key:generate
	$(APP) php artisan migrate
	@echo ""
	@echo "  ✓ Instalación completa"
	@echo "  → App:      http://localhost:8110"
	@echo "  → Admin:    http://localhost:8110/admin"
	@echo ""

up: ## Arranca todos los servicios en background
	$(DC) up -d

down: ## Detiene todos los servicios
	$(DC) down

restart: down up ## Reinicia todos los servicios

build: ## (Re)build de las imágenes
	$(DC) build

rebuild: ## Rebuild forzado sin cache
	$(DC) build --no-cache

status: ## Estado de los containers
	$(DC) ps

logs: ## Tail de logs de todos los servicios
	$(DC) logs -f --tail=100

reload: ## Señaliza a los workers que recarguen el código (tras cambios o deploy)
	$(APP) php artisan queue:restart

# ------------------------------------------------------------
# Shell / herramientas
# ------------------------------------------------------------
shell: ## Shell dentro del container app (usuario logistics)
	$(APP) bash

root-shell: ## Shell como root dentro del container app
	$(APP_ROOT) bash

composer: ## Composer dentro del container. Uso: make composer c="require x/y"
	$(APP) composer $(c)

artisan: ## Artisan dentro del container. Uso: make artisan c="route:list"
	$(APP) php artisan $(c)

tinker: ## Tinker interactivo
	$(APP) php artisan tinker

db-shell: ## psql sobre la base de desarrollo
	$(DC) exec postgres psql -U logistics -d logistics

valkey-shell: ## valkey-cli
	$(DC) exec valkey valkey-cli

# ------------------------------------------------------------
# Base de datos
# ------------------------------------------------------------
migrate: ## Corre migraciones pendientes
	$(APP) php artisan migrate

fresh: ## Recrea la base desde cero (BORRA DATOS de desarrollo)
	$(APP) php artisan migrate:fresh

seed: ## Corre los seeders
	$(APP) php artisan db:seed

# ------------------------------------------------------------
# Calidad
# ------------------------------------------------------------
test: ## Suite completa (Pest) contra la base logistics_test
	$(APP) composer test

test-unit: ## Solo tests unitarios (sin base de datos)
	$(APP) composer test:unit

coverage: ## Tests con cobertura (mínimo 75%)
	$(APP) composer test:coverage

pint: ## Verifica estilo de código
	$(APP) composer pint:check

pint-fix: ## Corrige estilo de código
	$(APP) composer pint

stan: ## Análisis estático (PHPStan nivel 8)
	$(APP) composer stan

lint: ## Pint + PHPStan
	$(APP) composer lint

ci: ## Lo mismo que corre el CI (dentro del container): lint + tests
	$(APP) composer ci

# ------------------------------------------------------------
# Frontend (admin / tablero de planificación)
# ------------------------------------------------------------
npm-install: ## npm install en el container node
	$(NODE) npm install

npm-dev: ## Vite dev server
	$(DC) --profile node up node

assets: ## Compila el tema del admin + assets (igual que el job `assets` del CI)
	# ----------------------------------------------------------------------------------------
	# Build del tema Filament (V-19) — procedimiento reproducible, sin copiar a mano.
	#
	# El tema custom del panel (`resources/css/filament/admin/theme.css`) hace
	# `@import '../../../../vendor/filament/filament/resources/css/theme.css'` y Tailwind
	# escanea las vistas Blade que viven bajo `vendor/filament/**`. Por eso el build necesita
	# tener `vendor/filament` presente en el árbol del proyecto, NO solo dentro del container
	# app (donde `vendor/` vive en un volumen que el container `node` no monta).
	#
	# Procedimiento reproducible (mismo que el job `assets` del CI, ver docs/deploy.md §2):
	#   1. Instalar las dependencias PHP para que exista `vendor/filament` en el árbol.
	#      En CI esto es `composer install` nativo; en dev reutilizamos el composer del
	#      container app y materializamos `vendor/filament` en el host con un `composer install`
	#      destinado al build (idempotente: si ya existe, no hace nada costoso).
	#   2. `npm ci` — dependencias JS desde package-lock.json.
	#   3. `npm run build` — Vite compila app.css/app.js + el tema del admin a public/build.
	#
	# En producción NO se corre este target: el tema se compila dentro del build de la imagen
	# (stage `assets` del Dockerfile), donde `vendor/filament` ya está instalado. Ver docs/deploy.md.
	# ----------------------------------------------------------------------------------------
	@echo "→ Asegurando vendor/filament en el árbol del proyecto (lo necesita el build del tema)..."
	@mkdir -p vendor
	@# Si vendor/filament no está en el host, traerlo del container app (que ya lo tiene instalado).
	@# Es idempotente: solo copia cuando falta, no pisa un vendor/filament ya presente.
	@if [ ! -d vendor/filament ]; then \
		echo "  vendor/filament ausente → copiando desde el container app..."; \
		$(DC) cp app:/var/www/html/vendor/filament ./vendor/filament; \
	else \
		echo "  vendor/filament ya presente → se reutiliza."; \
	fi
	$(NODE) npm ci
	$(NODE) npm run build
	@echo ""
	@echo "  ✓ Assets compilados en public/build"
