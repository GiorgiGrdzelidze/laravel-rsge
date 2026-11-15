<p align="center">
  <img src="https://img.shields.io/packagist/v/giorgigrdzelidze/laravel-rsge?include_prereleases&style=flat-square" alt="Latest Version">
  <img src="https://img.shields.io/packagist/dependency-v/giorgigrdzelidze/laravel-rsge/php?style=flat-square" alt="PHP Version">
  <img src="https://img.shields.io/github/license/GiorgiGrdzelidze/laravel-rsge?style=flat-square" alt="License">
  <a href="https://github.com/GiorgiGrdzelidze/laravel-rsge/actions/workflows/ci.yml"><img src="https://github.com/GiorgiGrdzelidze/laravel-rsge/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

# 🇬🇪 Laravel RSGE

> A production-grade Laravel package for the **Georgian Revenue Service (rs.ge)** SOAP API.
> Cash register Z-reports and electronic waybills today — taxpayer info and more to come.

---

## ✨ Features

- 🧾 **Cash Register Z-Reports** — fetch daily Z-report details & sums
- 🚚 **Electronic Waybills** — save, activate, close, cancel, read, list and print waybills, as any of the taxpayer's service users
- 🔐 **Basic Auth** — username/password authentication out of the box
- 🔄 **Retry with Backoff** — automatic retry on transient rs.ge system errors
- 🧪 **FakeTransport** — deterministic unit testing without hitting rs.ge
- 🏗️ **Extensible Architecture** — add new rs.ge services in minutes
- 🛡️ **Typed Exceptions** — `RsgeAuthenticationException`, `RsgeNotFoundException`, `RsgeSystemException`, `RsgeTransportException`, `RsgeWaybillException`, `RsgeUnreadMessageException`, `RsgeConfigurationException`
- 📦 **Zero Config** — Laravel auto-discovery, sensible defaults

---

## 📋 Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.2+ |
| Laravel | 12.x / 13.x |
| Extensions | `ext-soap`, `ext-simplexml` |

---

## 🚀 Installation

```bash
composer require giorgigrdzelidze/laravel-rsge
```

Publish the config file (optional):

```bash
php artisan vendor:publish --tag=rsge-config
```

---

## ⚙️ Configuration

Add to your `.env`:

```dotenv
RSGE_USERNAME=your_username
RSGE_PASSWORD=your_password
```

<details>
<summary>🔧 Optional settings</summary>

```dotenv
RSGE_CASH_REGISTER_WSDL=https://services.rs.ge/taxservice/taxpayerservice.asmx?WSDL
RSGE_WAYBILL_WSDL=https://services.rs.ge/WayBillService/WayBillService.asmx?WSDL
RSGE_LOGGING_ENABLED=true
RSGE_LOG_CHANNEL=stack
```

</details>

---

## 📖 Usage

### 🧾 Fetch Z-Report Details

```php
use Rsge\Laravel\Facades\Rsge;

$reports = Rsge::cashRegister()->getZReportDetails(
    startDate: '2025-01-01',
    endDate: '2025-01-31',
);

foreach ($reports as $report) {
    echo "{$report->deviceNumber}: {$report->paidAmount} GEL ({$report->quantity} receipts)\n";
}
```

### 📅 Using Carbon Dates

```php
use Carbon\CarbonImmutable;
use Rsge\Laravel\Facades\Rsge;

$start = CarbonImmutable::now()->startOfMonth();
$end = CarbonImmutable::now()->endOfMonth();

$reports = Rsge::cashRegister()->getZReportDetails($start, $end);
```

### 🚚 Electronic Waybills

The waybill service signs in with a **service user** (`su` / `sp`), made on the rs.ge portal. Its name has the form `NAME:TIN`.

```env
RSGE_WAYBILL_SU="SHOP1:206322102"
RSGE_WAYBILL_SP=secret
```

```php
use Rsge\Laravel\Facades\Rsge;
use Rsge\Laravel\Services\Waybill\Enums\WaybillType;
use Rsge\Laravel\Services\Waybill\Requests\GoodsLine;
use Rsge\Laravel\Services\Waybill\Requests\WaybillDraft;

$waybills = Rsge::waybill();

$saved = $waybills->save(new WaybillDraft(
    type: WaybillType::WithoutTransport,
    goods: [
        new GoodsLine(name: 'ფანქარი HB', quantity: 2, price: 1.5, amount: 3, barCode: '02345678'),
    ],
    buyerTin: '206322102',
    buyerName: 'მყიდველი',
    startAddress: 'თბილისი, ტესტის 1',
    endAddress: 'თბილისი, ტესტის 1',
    comment: 'R-2026-0007',
));

$number = $waybills->activate($saved->id);   // the waybill number; from here it can only be cancelled
$waybill = $waybills->get($saved->id);       // status, number, goods, S_USER_ID …
$pdf = $waybills->printPdf($saved->id);

$waybills->close($saved->id);
// or: $waybills->cancel($saved->id, 'შეცდომით გამოიწერა');
// a waybill that is only saved: $waybills->delete($saved->id);
```

Acting as another service user, for instance the member of staff who issues the waybill (rs.ge records it on the waybill as `S_USER_ID`):

```php
use Rsge\Laravel\Auth\ServiceUserCredentials;

$asStaff = Rsge::waybill(new ServiceUserCredentials('NINO:206322102', $password));
$check = $asStaff->checkServiceUser();       // valid, unId, serviceUserId
```

Other operations: `serviceUsers($mainUser, $mainPassword)`, `list(WaybillFilter)`, `getByNumber()`, `types()`, `units()`, `transportTypes()`, `errorCodes()`, `nameFromTin()`, `whatIsMyIp()`, `serverTime()`.

What rs.ge or the network refuses is an `RsgeException` carrying rs.ge's code and its Georgian text where there is one (`errorCode`, `errorDescription`). Input the package itself cannot send — a name with nothing readable in it, a number that is not one — is an `\InvalidArgumentException` when the `GoodsLine` or `WaybillDraft` is made:

| Exception | When |
|-----------|------|
| `RsgeWaybillException` | rs.ge refuses the operation with one of its codes; `lineErrors` names the goods lines at fault |
| `RsgeUnreadMessageException` (a `RsgeWaybillException`) | a mandatory message on the portal has to be read first (`-1061`); rs.ge has no web-service call for it, someone has to open the portal |
| `RsgeAuthenticationException` (not a `RsgeWaybillException`) | wrong service user or password (`-100`) |
| `RsgeConfigurationException` | no service user is configured; nothing was sent |
| `RsgeTransportException` | the call did not get through |

Dates go to rs.ge and come back as Tbilisi time, whatever timezone the application keeps: pass any `DateTimeInterface`, and the dates of a `Waybill` are moments in `Asia/Tbilisi`.

Credentials are not meant to travel: `ServiceUserCredentials` hides its password from dumps and JSON and refuses to be serialized, so pass an id to a queued job and load the password there.

In an application's tests, replace the service so that nothing reaches rs.ge:

```php
use Rsge\Laravel\Auth\ServiceUserCredentials;
use Rsge\Laravel\Services\Waybill\WaybillService;
use Rsge\Laravel\Transport\FakeTransport;

$transport = new FakeTransport;
$this->app->instance(WaybillService::class, new WaybillService($transport, new ServiceUserCredentials('TEST:206322102', 'x')));
```

`Rsge::waybill($credentials)` keeps that transport. Binding `Transport::class` alone replaces only the cash register's transport.

Things rs.ge does that are easy to trip over:

- `create_service_user` answers `true` and creates nothing; service users are made on the portal.
- `get_service_users` needs the taxpayer's main user and password, not a service user.
- A list (`get_waybills`) needs a creation-date range of at most 72 hours and leaves deleted waybills out.
- `ACTIVATE_DATE` is filled as soon as a waybill is saved; `Waybill::$activatedAt` is set only once the waybill has a number.
- Roles and permissions of portal users, and portal messages, are not available through the service.

### 🛡️ Exception Handling

```php
use Rsge\Laravel\Exceptions\RsgeAuthenticationException;
use Rsge\Laravel\Exceptions\RsgeNotFoundException;
use Rsge\Laravel\Exceptions\RsgeSystemException;
use Rsge\Laravel\Exceptions\RsgeTransportException;
use Rsge\Laravel\Facades\Rsge;

try {
    $reports = Rsge::cashRegister()->getZReportDetails('2025-01-01', '2025-01-31');
} catch (RsgeAuthenticationException $e) {
    // ❌ Invalid username or password
} catch (RsgeNotFoundException $e) {
    // 📭 No data for the given date range
} catch (RsgeSystemException $e) {
    // ⚠️ Transient rs.ge system error
} catch (RsgeTransportException $e) {
    // 🌐 Network or SOAP protocol failure
}
```

### 🔄 Retry on Transient Errors

```php
use Rsge\Laravel\Facades\Rsge;
use Rsge\Laravel\Support\Retry;

$reports = Retry::onSystemError(
    operation: fn () => Rsge::cashRegister()->getZReportDetails('2025-01-01', '2025-01-31'),
    attempts: 3,
    baseDelayMs: 200,
);
```

---

## 🏗️ Architecture

```
src/
├── Contracts/            # 📜 Interfaces (Transport, Credentials, Service)
├── Auth/                 # 🔐 Authentication (BasicCredentials)
├── Transport/            # 🚛 SoapTransport + FakeTransport for testing
├── Exceptions/           # 💥 Typed exceptions mapped from rs.ge error codes
├── Support/              # 🛠️ ErrorMapper, Retry helper
├── Services/
│   ├── AbstractService   # 🧩 Base class for all services
│   ├── CashRegister/     # 🧾 Z-Report service, DTOs, request objects
│   └── Waybill/          # 🚚 Waybill service, DTOs, request objects, enums
├── Facades/              # 🎭 Rsge facade
├── Rsge.php              # 🏭 Manager (service factory)
└── RsgeServiceProvider   # 📦 Laravel service provider
```

---

## 🗺️ Roadmap

| Service | SOAP Method | Status |
|---------|-------------|--------|
| Cash Register Z-Report Details | `Get_Z_Report_Details` | ✅ Done |
| Electronic waybills | `save_waybill`, `del_waybill`, `get_waybill`, `get_waybills`, `chek_service_user`, `get_service_users`, lists | ✅ Done, checked against rs.ge |
| Electronic waybills | `send_waybill`, `send_waybil_vd`, `close_waybill`, `ref_waybill`, `get_print_pdf` | ✅ Done, live check pending |
| Cash Register Z-Report Sum | `Get_Z_Report_Sum` | 🔜 Planned |
| Taxpayer Public Info | `GetTPInfoPublic` | 🔜 Planned |
| Taxpayer Public Contacts | `GetTPInfoPublicContacts` | 🔜 Planned |
| Payer Info | `Get_Payer_Info` | 🔜 Planned |
| Legal Person Info | `Get_LegalPerson_Info` | 🔜 Planned |
| Waybill Month Amount | `Get_Waybill_Month_Amount` | 🔜 Planned |
| Income Amount | `Get_Income_Amount` | 🔜 Planned |
| Comparison Act | `Get_comp_act_new` | 🔜 Planned |
| Payer NACE Info | `Get_Payer_Nace_Info` | 🔜 Planned |
| QuickCash Info | `Get_QuickCash_Info` | 🔜 Planned |

---

## 🧩 Adding a New Service

1. Create **DTOs** in `src/Services/YourService/DTOs/` with `fromSoap()` method
2. Create **Requests** in `src/Services/YourService/Requests/` with `toSoap()` method
3. Create a **Service** class extending `AbstractService`
4. Register in `RsgeServiceProvider` as a singleton
5. Add accessor to `Rsge` manager + update facade PHPDoc
6. Write **tests** using `FakeTransport`

---

## 🧪 Testing & QA

```bash
# 🚀 Full QA pipeline (lint + static analysis + tests)
composer qa

# Individual commands
composer pint:test    # ✏️ Code style (Pint)
composer stan         # 🔍 Static analysis (PHPStan level max)
composer test         # ✅ Tests (PHPUnit)
```

---

### Live tests

`composer test` never talks to rs.ge. A separate suite does, against rs.ge's two published **test** taxpayers only:

```bash
cp phpunit.live.xml.dist phpunit.live.xml   # git-ignored; put a test service user in
composer test:live
```

With `RSGE_LIVE_WRITES=1` the suite also saves, activates, closes, cancels and deletes its own test waybills, from one test taxpayer to the other. Before anything else it asks rs.ge which taxpayer the service user belongs to, and stops unless that is one of the two test codes.

---

## 📄 License

MIT — see [LICENSE](LICENSE) file.

---

<p align="center">
  Made with ❤️ in Georgia 🇬🇪
</p>
