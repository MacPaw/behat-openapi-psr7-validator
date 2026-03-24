# AGENTS.md

## Cursor Cloud specific instructions

This is a PHP library (Symfony Behat extension) — not a runnable application. There are no servers to start, no databases, and no Docker required for development.

### Key commands

All commands are defined in `composer.json` scripts section:

| Task | Command |
|------|---------|
| Tests | `composer test` or `vendor/bin/phpunit` |
| Lint | `composer cs:check` or `vendor/bin/php-cs-fixer fix --dry-run --diff` |
| Lint fix | `composer cs:fix` |
| Static analysis | `composer phpstan` or `vendor/bin/phpstan analyse` |
| Full QA | `composer qa` (runs cs:check + phpstan + test) |
| Coverage | `composer test:coverage` (requires pcov extension) |

### Known issues

- PHP-CS-Fixer emits a warning when run on PHP 8.3+ because `composer.json` declares minimum PHP 8.2. This is cosmetic and does not affect results.

### System dependencies

PHP 8.3 with extensions: `xml`, `mbstring`, `curl`, `zip`, `pcov`. Installed via `ppa:ondrej/php`.
