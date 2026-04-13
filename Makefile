.PHONY: help \
        up down build logs \
        install migrate migrate-test fixtures cc shell db-shell redis-cli \
        test test-unit test-integration test-setup test-coverage \
        local-install local-migrate local-migrate-test local-fixtures local-serve \
        local-test local-test-unit local-test-integration local-test-setup

# ── Colours ───────────────────────────────────────────────────────────────────
GREEN  := \033[0;32m
YELLOW := \033[0;33m
CYAN   := \033[0;36m
RESET  := \033[0m

help: ## Show this help
	@echo ""
	@echo "$(CYAN)╔══════════════════════════════════════════════╗$(RESET)"
	@echo "$(CYAN)║         Fund Transfer API – Commands         ║$(RESET)"
	@echo "$(CYAN)╚══════════════════════════════════════════════╝$(RESET)"
	@echo ""
	@echo "$(YELLOW)── Docker commands ──────────────────────────────$(RESET)"
	@grep -E '^[a-z][a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | grep -v "^local" | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-28s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(YELLOW)── Local (no-Docker) commands ───────────────────$(RESET)"
	@grep -E '^local-[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-28s$(RESET) %s\n", $$1, $$2}'
	@echo ""

# ══════════════════════════════════════════════════════════════════════════════
# DOCKER COMMANDS
# ══════════════════════════════════════════════════════════════════════════════

# ── Container lifecycle ───────────────────────────────────────────────────────
up: ## Start all containers (builds if needed)
	docker compose up -d --build

down: ## Stop containers (data is preserved in named volumes)
	docker compose down

down-clean: ## ⚠️  Stop containers AND delete ALL data (volumes included)
	docker compose down -v

build: ## Rebuild images without cache
	docker compose build --no-cache

logs: ## Tail all container logs
	docker compose logs -f

# ── Application setup ─────────────────────────────────────────────────────────
install: ## Install Composer dependencies
	docker compose exec app composer install --no-interaction --prefer-dist

migrate: ## Run DB migrations (dev)
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

migrate-test: ## Run DB migrations (test DB)
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction --env=test

fixtures: ## Load sample data (dev only)
	docker compose exec app php bin/console doctrine:fixtures:load --no-interaction

cc: ## Clear Symfony cache
	docker compose exec app php bin/console cache:clear

# ── Testing ───────────────────────────────────────────────────────────────────
test-setup: ## Create & migrate test DB (run once)
	docker compose exec app php bin/console doctrine:database:create --if-not-exists --env=test
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction --env=test

test: ## Run full test suite
	docker compose exec app php bin/phpunit --colors=always

test-unit: ## Run unit tests only (no DB needed)
	docker compose exec app php bin/phpunit tests/Unit --colors=always

test-integration: ## Run integration tests (needs test-setup first)
	docker compose exec app php bin/phpunit tests/Integration --colors=always

test-coverage: ## Generate HTML coverage report → var/coverage/
	docker compose exec app php bin/phpunit --coverage-html var/coverage --colors=always

# ── Access / debugging ────────────────────────────────────────────────────────
shell: ## Open bash shell in app container
	docker compose exec app bash

db-shell: ## Open MySQL shell (dev DB)
	docker compose exec db mysql -u app_user -papp_pass fund_transfer

db-shell-test: ## Open MySQL shell (test DB)
	docker compose exec db mysql -u app_user -papp_pass fund_transfer_test

redis-cli: ## Open Redis CLI
	docker compose exec redis redis-cli

# ══════════════════════════════════════════════════════════════════════════════
# LOCAL (no-Docker) COMMANDS  – prefix: local-
# Requires: PHP 8.3, MySQL 8, Redis 7, Composer installed on your machine.
# First time: cp .env.local.example .env.local  and edit credentials.
# ══════════════════════════════════════════════════════════════════════════════

local-install: ## Install Composer deps locally
	composer install --no-interaction --prefer-dist

local-migrate: ## Run DB migrations locally (dev)
	php bin/console doctrine:migrations:migrate --no-interaction

local-migrate-test: ## Run DB migrations locally (test DB)
	php bin/console doctrine:migrations:migrate --no-interaction --env=test

local-fixtures: ## Load sample data locally (dev only)
	php bin/console doctrine:fixtures:load --no-interaction

local-cc: ## Clear Symfony cache locally
	php bin/console cache:clear

local-serve: ## Start built-in PHP dev server on :8000
	php -S 0.0.0.0:8000 -t public/

local-serve-symfony: ## Start Symfony CLI dev server (if symfony CLI installed)
	symfony serve --port=8000

# ── Testing (local) ───────────────────────────────────────────────────────────
local-test-setup: ## Create & migrate local test DB
	php bin/console doctrine:database:create --if-not-exists --env=test
	php bin/console doctrine:migrations:migrate --no-interaction --env=test

local-test: ## Run full test suite locally
	php bin/phpunit --colors=always

local-test-unit: ## Run unit tests locally
	php bin/phpunit tests/Unit --colors=always

local-test-integration: ## Run integration tests locally
	php bin/phpunit tests/Integration --colors=always
