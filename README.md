# Fund Transfer API

PHP 8.3 · Symfony 7.2 · MySQL 8 · Redis 7

---

## Prerequisites

- Docker + Docker Compose, **or** PHP 8.3 + MySQL 8 + Redis 7 locally
- PHP extensions: `ext-bcmath`, `ext-pdo_mysql`, `ext-redis`

---

## Quick Start — Docker

```bash
# 1. Start all services
docker compose up -d

# 2. Install dependencies
docker compose exec app composer install --no-interaction --prefer-dist

# 3. Create databases
docker compose exec app php bin/console doctrine:database:create --if-not-exists
docker compose exec app php bin/console doctrine:database:create --if-not-exists --env=test

# 4. Run migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console doctrine:migrations:migrate --env=test --no-interaction

# 5. Load sample data (Alice, Bob, Carol + test API key)
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction

# 6. Clear cache
docker compose exec app php bin/console cache:clear
```

App: **http://localhost:8080**  
Health: **http://localhost:8080/health**  
phpMyAdmin: **http://localhost:8081**

Test API key: `test-api-key-fixture-00000000000001`

---

## Running Tests

```bash
# All tests (Docker)
docker compose exec app php bin/phpunit

# Unit tests only — no DB or Redis required
php bin/phpunit --testsuite unit --no-coverage

# Integration tests (requires DB + Redis)
php bin/phpunit --testsuite integration --no-coverage
```

When running tests from the host machine against the Dockerized services, MySQL is exposed on `127.0.0.1:13306` and Redis on `127.0.0.1:6379` via `.env.test`.

Or via Make:

```bash
make test               # Full suite
make test-unit          # Unit only
make test-integration   # Integration only
make test-setup         # First-time: create + migrate test DB
```

---

## Key Make Targets

```
make up             Start Docker stack
make down           Stop Docker stack
make install        composer install
make migrate        Run DB migrations (dev)
make fixtures       Load sample data
make cc             Clear Symfony cache
make shell          Bash into app container
make redis-cli      Redis CLI
make db-shell       MySQL CLI
make help           List all targets
```
