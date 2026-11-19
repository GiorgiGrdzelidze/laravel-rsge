# Changelog

All notable changes to `laravel-rsge` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0-beta.4] - 2026-11-20

### Fixed

- `WaybillService::nameFromTin()` throws `RsgeAuthenticationException` (code -100) for a service user rs.ge does not accept. rs.ge answers `get_name_from_tin` for such a user without an error code, exactly as for a number nobody is registered under, so until now a wrong password read as "nobody has this number" for every number. When the answer is "nobody", the service user is now checked with `chek_service_user` first (one more call, only for such answers). A test that fakes a "nobody" answer has to queue the answer of that check too.

### Notes from the live service (2026-11-20, rs.ge's test taxpayers)

- The two published test main users are not accepted as service users: `chek_service_user` answers false and other calls answer -100.
- `get_name_from_tin` with a wrong password: no error code, the same answer as for an unknown number, also for a number that is registered.

## [1.1.0-beta.3] - 2026-11-19

### Fixed

- `WaybillService::nameFromTin()` returns null for a number nobody is registered under. rs.ge answers `get_name_from_tin` for such a number with the text `null`, and until now that text came back as the name.

### Notes from the live service (2026-11-19)

- `get_name_from_tin` answers in about 0.3 s. The name comes without its legal form: `402039049` is `ნიკე - თანამედროვე ხელოვნების განვითარებისათვის`.

## [1.1.0-beta.2] - 2026-11-17

### Added

- A waybill for a carrier company: `WaybillDraft::$handedToCarrier` stores the waybill as sent to the carrier named by `$transporterTin` (status 8, transport type `Carrier`, no driver and no car). The carrier puts its driver and car on it and starts the transport; only then does rs.ge give the waybill a number, so `get()` is asked again later for it. A draft that could not be handed over (another waybill type or transport type, no carrier, a driver or car of the seller's own) is refused before anything is sent.

### Fixed

- `WaybillFilter`: the `statuses` and `serviceUserIds` filters are sent the way rs.ge reads them. Until now a list narrowed by either came back empty.

### Changed

- `cancel()` with a comment: when rs.ge answers `ref_waybill_vd` with 0 and changes nothing, as it does for a waybill that is with a carrier, the waybill is cancelled with `ref_waybill` and the comment is dropped. A test that fakes the answer 0 has to queue the answer of the second call too.

### Notes from the live service (2026-11-17, rs.ge's test taxpayers)

- There is no call by which a seller sends a saved waybill to a carrier: `save_waybill` with `STATUS` 8 is the hand-over. `send_waybill` on a waybill that only names a carrier activates it without a driver, and `send_waybill_transporter` belongs to the carrier (the seller gets -1019).
- While the waybill is with the carrier the seller can save it again (same id, `STATUS` 8 again) and cancel it; `send_waybill` answers -1, `close_waybill` -1018, `del_waybill` 0.
- rs.ge's refusals for such a waybill: -1002 without the transport type, -1012 with a transport type other than the carrier's and no driver, -1048 for a tax id that is no taxpayer's, -1050 without one, -1011 without the end address.
- `get_waybills` reads a list of statuses or of service users only inside commas (`,0,8,`); written plainly the filter matched nothing.
- A second `del_waybill` on a deleted waybill answers like the first; `send_waybill` on a waybill without goods answers -1.
- `send_waybill`, `ref_waybill`, `ref_waybill_vd`, `close_waybill` and `get_print_pdf` have now been run against rs.ge. `ref_waybill_vd` on an active waybill answers 1 and rs.ge keeps its comment.

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

[Unreleased]: https://github.com/GiorgiGrdzelidze/laravel-rsge/compare/v1.1.0-beta.2...HEAD
[1.1.0-beta.2]: https://github.com/GiorgiGrdzelidze/laravel-rsge/compare/v1.1.0-beta.1...v1.1.0-beta.2
[1.1.0-beta.1]: https://github.com/GiorgiGrdzelidze/laravel-rsge/compare/v1.0.0...v1.1.0-beta.1
[1.0.0]: https://github.com/GiorgiGrdzelidze/laravel-rsge/releases/tag/v1.0.0
