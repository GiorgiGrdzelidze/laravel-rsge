# Changelog

All notable changes to `laravel-rsge` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-04-17

### Added

- Core architecture: `Transport`, `Credentials`, `Service` contracts; `AbstractService` base class.
- `SoapTransport` wrapping PHP's `SoapClient` with optional PSR-3 logger.
- `FakeTransport` for deterministic unit tests.
- `BasicCredentials` auth strategy.
- Exception hierarchy: `RsgeException`, `RsgeAuthenticationException`, `RsgeNotFoundException`, `RsgeSystemException`, `RsgeTransportException`.
- `ErrorMapper` translating rs.ge error codes (0, 1, 2) into typed exceptions.
- `Retry::onSystemError()` helper with exponential backoff.
- First concrete service: `CashRegisterService::getZReportDetails()`.
- `ZReportDetail` readonly DTO with `fromSoap()` hydration (array + stdClass).
- Laravel 12/13 `RsgeServiceProvider`, `Rsge` manager, and `Rsge` facade.
- Publishable `config/rsge.php`.
- Unit tests (>30) and Testbench integration tests (5).
- PHPStan level max, Laravel Pint, GitHub Actions CI (PHP 8.2 / 8.3 / 8.4).

[Unreleased]: https://github.com/GiorgiGrdzelidze/laravel-rsge/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/GiorgiGrdzelidze/laravel-rsge/releases/tag/v1.0.0
