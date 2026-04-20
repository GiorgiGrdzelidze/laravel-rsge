<p align="center">
  <img src="https://img.shields.io/packagist/v/giorgigrdzelidze/laravel-rsge.svg?style=flat-square" alt="Latest Version">
  <img src="https://img.shields.io/packagist/php-v/giorgigrdzelidze/laravel-rsge.svg?style=flat-square" alt="PHP Version">
  <img src="https://img.shields.io/packagist/l/giorgigrdzelidze/laravel-rsge.svg?style=flat-square" alt="License">
  <a href="https://github.com/GiorgiGrdzelidze/laravel-rsge/actions/workflows/ci.yml"><img src="https://github.com/GiorgiGrdzelidze/laravel-rsge/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

# 🇬🇪 Laravel RSGE

> A production-grade Laravel package for the **Georgian Revenue Service (rs.ge)** SOAP API.
> Fetch cash register Z-reports today — waybills, taxpayer info, and more coming soon.

---

## ✨ Features

- 🧾 **Cash Register Z-Reports** — fetch daily Z-report details & sums
- 🔐 **Basic Auth** — username/password authentication out of the box
- 🔄 **Retry with Backoff** — automatic retry on transient rs.ge system errors
- 🧪 **FakeTransport** — deterministic unit testing without hitting rs.ge
- 🏗️ **Extensible Architecture** — add new rs.ge services in minutes
- 🛡️ **Typed Exceptions** — `RsgeAuthenticationException`, `RsgeNotFoundException`, `RsgeSystemException`, `RsgeTransportException`
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
│   └── CashRegister/     # 🧾 Z-Report service, DTOs, request objects
├── Facades/              # 🎭 Rsge facade
├── Rsge.php              # 🏭 Manager (service factory)
└── RsgeServiceProvider   # 📦 Laravel service provider
```

---

## 🗺️ Roadmap

| Service | SOAP Method | Status |
|---------|-------------|--------|
| Cash Register Z-Report Details | `Get_Z_Report_Details` | ✅ Done |
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

## 📄 License

MIT — see [LICENSE](LICENSE) file.

---

<p align="center">
  Made with ❤️ in Georgia 🇬🇪
</p>
