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
        lint pint pint-fix stan ci db-shell valkey-shell reload npm-install npm-dev

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
	@echo "  → Mailpit:  http://localhost:8055"
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

ci: ## Lo mismo que corre el CI: lint + tests
	$(APP) composer ci

# ------------------------------------------------------------
# Frontend (admin / tablero de planificación)
# ------------------------------------------------------------
npm-install: ## npm install en el container node
	$(NODE) npm install

npm-dev: ## Vite dev server
	$(DC) --profile node up node
