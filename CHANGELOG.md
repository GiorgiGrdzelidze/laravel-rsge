# Changelog

All notable changes to `laravel-rsge` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0-beta.1] - 2026-11-15

A pre-release: the read side of the waybill service was run against rs.ge, the write side beyond `save_waybill` / `del_waybill` was not (see the notes below).

### Added

- `WaybillService` for rs.ge's electronic waybill service (`Rsge::waybill()`): `save`, `activate` (`send_waybill` / `send_waybil_vd`), `close`, `cancel` (`ref_waybill`), `delete`, `get`, `getByNumber`, `list`, `printPdf`, `checkServiceUser`, `serviceUsers`, `types`, `units`, `transportTypes`, `errorCodes`, `nameFromTin`, `whatIsMyIp`, `serverTime`.
- `ServiceUserCredentials` (su / sp); `Rsge::waybill($credentials)` and `WaybillService::withCredentials()` act as another service user.
- `WaybillDraft`, `GoodsLine`, `WaybillFilter`; DTOs `Waybill`, `WaybillGoods`, `SavedWaybill`, `ServiceUser`, `ServiceUserCheck`, `DictionaryItem`, `ErrorCode`; enums `WaybillType`, `WaybillStatus`, `TransportType`, `VatType`.
- `RsgeWaybillException` with rs.ge's code, its text and per-line errors; `RsgeUnreadMessageException` for `-1061` (a mandatory portal message has to be read first). rs.ge's 150 error codes are bundled (`resources/waybill-error-codes.php`, as listed by `get_error_codes` on 2026-11-15).
- Config: `rsge.auth.waybill.{su,sp}` (`RSGE_WAYBILL_SU`, `RSGE_WAYBILL_SP`) and `rsge.endpoints.waybill` (`RSGE_WAYBILL_WSDL`). Keys a published config file does not have yet are filled in from the package's defaults, also inside `auth` and `endpoints`, so a file published from 1.0 needs no change.
- `RsgeConfigurationException` when the waybill service is used without a service user; nothing is sent to rs.ge.
- `WaybillService::tinFromUnId()`.
- `SoapTransport` sends an `XmlNode` parameter as a raw XML fragment; `SoapTransport::wsdl()`.
- A live test suite against rs.ge's test taxpayers (`composer test:live`, `phpunit.live.xml.dist`), off by default.

### Changed

- A SOAP fault is no longer chained as the previous exception of `RsgeTransportException` (its trace holds the whole request, or the client's options); the fault code and text are in the message. Credentials and call parameters are marked sensitive in both services, so passwords stay out of stack traces.
- Dates of the waybill service are written and read as Tbilisi time, whatever the application's timezone.

### Notes from the live service (2026-11-15)

- `save_waybill`, `get_waybill`, `del_waybill`, `get_waybills`, `chek_service_user`, `get_service_users` and the lists were checked against rs.ge's test taxpayers; the request `save_waybill` sends has the shape of one rs.ge accepted.
- `send_waybill`, `send_waybil_vd`, `close_waybill`, `ref_waybill` and `get_print_pdf` are implemented from the WSDL and have not been run against rs.ge yet.
- `create_service_user` returns `true` and creates nothing, so it is not wrapped.

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
