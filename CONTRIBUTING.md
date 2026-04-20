# Contributing to laravel-rsge

Thank you for considering contributing! This document outlines how to get started.

## Development Setup

```bash
git clone git@github.com:GiorgiGrdzelidze/laravel-rsge.git
cd laravel-rsge
composer install
```

## Quality Assurance

Before submitting a PR, run the full QA pipeline:

```bash
composer qa
```

This runs, in order:

1. **Laravel Pint** — code style (PSR-12 + Laravel preset)
2. **PHPStan level max** — static analysis
3. **PHPUnit** — unit + integration tests

All three must pass with zero errors.

## Coding Standards

- Every PHP file must start with `declare(strict_types=1)`.
- All classes should be `final` unless designed for extension.
- Public methods must have `@return` and `@throws` PHPDoc tags.
- Use `readonly` properties in DTOs.
- No `@phpstan-ignore` comments — fix the code instead.
- No `dd()`, `dump()`, or `var_dump()` in source or tests.

## Branch Naming

- `feature/<short-name>` — new functionality
- `fix/<short-name>` — bug fixes
- `docs/<short-name>` — documentation changes
- `refactor/<short-name>` — code restructuring

## Pull Request Format

PR titles should follow this format:

```
[area] verb in imperative mood
```

Examples:
- `[cash-register] add Z-report pagination support`
- `[transport] fix timeout handling for slow WSDL`
- `[docs] update installation instructions`

## Adding a New rs.ge Service

See the "Adding a New Service" section in [README.md](README.md) for a step-by-step guide.

In short:

1. Create DTOs with `fromSoap()` in `src/Services/YourService/DTOs/`.
2. Create Request classes with `toSoap()` in `src/Services/YourService/Requests/`.
3. Extend `AbstractService` for your service class.
4. Register in `RsgeServiceProvider`.
5. Add accessor to `Rsge` manager + update facade PHPDoc.
6. Write comprehensive tests using `FakeTransport`.

## Reporting Issues

Open an issue on GitHub with:
- PHP and Laravel versions
- Steps to reproduce
- Expected vs actual behavior
- Relevant error messages or stack traces
