# Contributing to Laravel FTS

## Development Setup

```bash
git clone https://github.com/moaines/illumi-search
cd illumi-search
composer install
```

## Running Tests

```bash
composer test              # phpunit
composer test -- --testdox # named tests (readable output)
composer analyse           # phpstan
composer format            # pint
```

## Code Style

- Follow PSR-12 (enforced by laravel/pint)
- PHP 8.2+ features encouraged (readonly, constructor promotion, match, named arguments)
- `declare(strict_types=1)` on all new files

## Pull Request Process

1. One change per PR. If you fix a bug and add a feature, split into two PRs.
2. Tests first — or at least tests alongside. New features require tests, bug fixes require a regression test.
3. Commit messages follow conventional commits (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`).
4. Update the Changelog in [README.md](README.md) under `### Unreleased`.
5. PHPStan must pass at level 5 (current baseline).

## Adding a New Model to FTS

See the [Searchable trait](README.md#searchable-trait) section in the README.

## Adding a New TextProcessor

See the [Custom TextProcessor](README.md#custom-processors) section in the README.

## Testing Multi-tenancy

See the [Multi-tenant](README.md#multi-tenant) section in the README for setup guidance.

## Report Bugs / Feature Requests

[GitHub Issues](https://github.com/moaines/illumi-search/issues)
