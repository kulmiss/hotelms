# Backend (Laravel)

## Folder map (`backend/`)

| Path | What is there |
|---|---|
| `routes/api.php` | Every API route, grouped by area, with its middleware and permission. Start here to find a feature. |
| `routes/console.php` | The schedule (cron jobs) |
| `bootstrap/app.php` | Middleware aliases and order, exception handling (JSON for `/api`, server errors noted for the watchdog) |
| `config/permissions.php` | Hotel permissions and the default roles that have them |
| `config/hotelms.php` | Platform roles and permissions, backups, Super Admin seed, two-step for admins |
| `config/switches.php` | Super Admin switches (feature flags) and their defaults |
| `config/automations.php` | The ready-made automations (what they do, default on/off, settings) |
| `config/smart.php` | Smart hints (each is also a switch `smart.<key>`) |
| `app/Http/Controllers/Api/` | Controllers by area: `Front/` (bookings, desk, guests, bills), `Modules/` (cashier, housekeeping, shifts, attendance), `Settings/`, `Hotel/` (staff, roles, setup, activity log), `Reports/`, `Automation/`, `Admin/` (Super Admin) |
| `app/Http/Presenters/` | `ReservationPresenter`, `InvoicePresenter`: the JSON shape of a booking and a bill (one place) |
| `app/Http/Middleware/` | See below |
| `app/Models/` | Eloquent models. Hotel data uses `Concerns\BelongsToHotel`; `Concerns\LogsActivity` writes the activity log |
| `app/Services/` | **Business rules live here**, not in controllers (see the map below) |
| `app/Support/` | `Money` (exact maths), `HotelClock` (time zones), `Tenancy/TenantContext`, `Spreadsheet` (CSV/XLSX read and write, formula-safe), `Totp`, `Permissions` |
| `app/Console/Commands/` | Artisan commands (below) |
| `app/Events`, `app/Listeners` | `GuestCheckedOut` → `CreateCleaningTasks` |
| `app/Mail/` + `resources/views/mail/` | Emails (branded with the platform logo and colour) |
| `database/migrations/` | Schema history ([data-model.md](data-model.md)) |
| `database/seeders/` | `PlatformSeeder`: plans, the Super Admin, platform settings |
| `lang/en/validation.php` | Plain-English validation wording and field names |
| `tests/Feature/` | Feature tests by area; `tests/Performance/` the big-hotel speed test |

## Middleware (aliases in `bootstrap/app.php`)

Order for hotel routes: `auth:sanctum` → `active` → `device` → `tenant` → `password.changed` → `two.factor` → `hotel.writable` → `emergency` → `permission:…`.

| Alias | Class | Does |
|---|---|---|
| `tenant` | `SetTenantContext` | Sets the current hotel from the signed-in user (before route binding) |
| `active` | `EnsureActiveUser` | A disabled account is signed out on its next request |
| `device` | `EnsureDeviceActive` | A device signed out or blocked by the Hotel Admin loses access; "approved devices only" |
| `password.changed` | `EnsurePasswordChanged` | First sign-in: choose your own password first |
| `two.factor` | `EnsureTwoFactorSetUp` | Admins (and staff, if the hotel wants) must set up two-step sign-in |
| `hotel.writable` | `EnsureHotelWritable` | A suspended hotel is read-only |
| `emergency` | `EnsureNoEmergency` | Super Admin emergency switches: maintenance / read-only |
| `permission:a,b` | `EnsureHotelPermission` | Needs any of these hotel permissions |
| `platform:x` | `EnsurePlatformPermission` | Super Admin team permission |
| `module:x` | `EnsureModuleEnabled` | The hotel has this optional module on |
| `switch:x` | `EnsureSwitchOn` | A Super Admin switch is on for this hotel |
| `not.impersonating` | `BlockDuringImpersonation` | Not allowed in a "log in as hotel" support session |
| `idempotent` | `Idempotent` | Saved once (owner, QA 8 Nov) on every action that takes money or creates / changes a booking, bill, charge, cashier shift, guest or approval: the `Idempotency-Key` + user + endpoint + request hash + answer are kept 24 h in `idempotency_keys` (unique per user and key). Same key → the first answer again; same key with other details → 422 `idempotency_mismatch`; same key still running → waits, then 409 `busy`; a failed answer frees the key |
| `one.at.a.time:kind` | `OneAtATime` | One change at a time per booking/bill (a lock in the cache) |
| (global) | `SecurityHeaders` | nosniff, frame deny, referrer policy, HSTS on HTTPS |
| (global) | Laravel `TrustProxies` | trusts nobody by default; `TRUSTED_PROXIES` in `.env` (via `App\Support\TrustedProxies`, set in `AppServiceProvider`) only behind a proxy/CDN – see hosting.md |
| (global) | `RecordProblems` | Notes refused requests and errors for the watchdog |

Rate limits (`AppServiceProvider`): `login` (5/min per email+address, 30/min per address), `two-factor`
(per session and per address; plus 10 wrong codes per person per 15 min in `AuthController`), `pin` (per hotel).

## Services map (`app/Services/`)

| Service | Responsibility |
|---|---|
| `Booking/ReservationService` | Create/change bookings, price preview, deposits, payments (`recordPayment`, `payAcrossBills`, overpay check), cancel/no-show with the policy, pay-later |
| `Booking/StayQuote` | Stay times (nightly / day use / hourly) and the price of one room; taxes; deposit |
| `Booking/Availability` | Free rooms for a time range (no overbooking), cleaning gap for hourly stays |
| `Booking/FrontDesk` | Walk-in (night cut-off), check-in (early fee, ID photo), check-out (late fee, hourly overstay, **early departure**, balance approval), room move, extend, room status |
| `PriceCalculator` | Price per night with rate rules (weekend, season, guest type) |
| `Billing/InvoiceService` | Bills: open at check-in, lines, charges, discounts (role limit), recalc totals, split per room, cancel ("void"), outstanding approval |
| `Billing/TaxCalculator` | Taxes per line (inclusive / added; rooms / extras / all) |
| `Billing/CurrencyConverter` | Latest exchange rate entered by the hotel |
| `Modules/Cashier` | Cashier shifts: open, cash linked to the open shift, expected vs counted, approval |
| `Modules/Housekeeping` | Cleaning tasks: one per dirty room (also made dirty in bulk), start/done, inspections |
| `Modules/Attendance` | Clock in/out, kiosk PIN, manual entries, lateness against the roster |
| `HotelSettings` | Per-hotel settings (key/value with defaults: `BOOKING_DEFAULTS`, `INVOICE_DEFAULTS`, `SECURITY_DEFAULTS`…) |
| `HotelModules`, `Switches` | Optional modules; Super Admin switches |
| `HotelDefaults`, `DefaultRoles` | What a new hotel starts with (roles, currencies, taxes, payment methods, policies) |
| `HotelOnboarding`, `HotelSetup` | Creating a hotel; the 12-step setup walkthrough |
| `HotelStatus`, `HotelBilling`, `Subscriptions` | Trial → active → grace → suspended; HotelMS invoices to hotels; subscription payments |
| `HotelClosing`, `HotelExport` | Close a hotel (data kept 90 days, files deleted); download all of a hotel's data |
| `Impersonation` | "Log in as hotel" with a reason, logged |
| `Security/TwoFactor`, `Security/Devices` | Authenticator app / email codes, recovery codes; devices, new-device alerts, approved devices |
| `GuestIdPhotos` | Encrypted ID/passport photos, kept as long as the hotel chose |
| `Imports/RoomImporter` | Import rooms (and more) from Excel/CSV, with check and undo |
| `Reports/Metrics`, `Reports/Insights` | Report numbers (KPIs, daily, forecast, targets, staff audit, accounting journal) |
| `Reports/CustomReportEngine` | The custom report builder (whitelisted measures and groups) |
| `Reports/Automation` | Scheduled reports (email/Telegram) and smart alerts |
| `Automation/*` | Ready-made automations (events and schedule), the rule builder, platform automations |
| `Smart/SmartHints` | Free "smart" hints with a "Why?" explanation on many screens (the dashboard's week-on-week insight is cached 5 minutes) |
| `Notify/Notifier`, `Notify/Telegram`, `Notify/MailSettings` | In-app bell, email, Telegram bot (polling, link by code); SMTP from Super Admin settings |
| `Watchdog/WatchEvents`, `Watchdog/Watchdog` | Super Admin watchdog: password guessing, snooping, errors, cron/backup, mixed hotel data, money mismatches, double bookings, fraud signs |
| `Backup/Backups` | Database backups (daily full, hourly small, monthly check, restore) and upload mirroring |
| `Backup/BackupCipher` | Encrypted backup files (libsodium secretstream + gzip, streamed in 64 KB chunks) with `BACKUP_KEY`; `hotelms:backup-key` makes a key, `hotelms:backup --decrypt=` makes a readable copy |
| `ActivityLogger` | The activity log (who, what, when, before/after) |
| `DemoHotel`, `Demo/*` | The demo hotel |

**Convention:** controllers validate input, check permission/branch, call one service method, and return a presenter or resource.
Rules (money, dates, status changes) belong in services so tests, automations and imports share them.

## Scheduler (`routes/console.php`)

| When | Job |
|---|---|
| every minute | `reservations:expire` (unpaid pending bookings), `reports:automation` (scheduled reports + Telegram polling), `queue:work --stop-when-empty`, heartbeat |
| every 5 min | `automations:run`, `stays:roll-open` (one more night for guests with no end date) |
| every 10 min | `watchdog:run` (quick checks) |
| every 15 min | `reports:automation --alerts` (smart alerts) |
| hourly | `hotels:update-statuses`, backup `--small` (:40), `watchdog:run --data` (:17) |
| daily | 01:15 full backup · 02:30 `demo:reset` · 03:20 old unused payment proofs · 03:30 `hotelms:purge-closed-hotels` · 03:40 old guest ID photos · 07:10 `subscriptions:invoices` |
| monthly | backup `--check` (1st, 06:00) |

## Commands

| Command | What |
|---|---|
| `demo:reset {--rooms=60}` | Rebuild the demo hotel (more rooms for a speed test) |
| `hotelms:backup {--small} {--check} {--restore=file} {--force}` | Backups ([hosting.md](hosting.md)) |
| `hotelms:reset-two-factor {email}` | Turn off two-step sign-in for someone who lost their phone |
| `hotelms:purge-closed-hotels` | Delete hotels closed more than 90 days ago, with their files |
| `watchdog:run {--data}` | Run the watchdog checks now |
| `reports:automation {--alerts}`, `automations:run`, `reservations:expire`, `stays:roll-open`, `hotels:update-statuses`, `subscriptions:invoices` | The scheduled jobs, by hand |

## Conventions
- **Lists** use `App\Support\ListQuery`: `sort` + `dir` (only the keys the list allows), `per_page` (25/50/100), and `format=xlsx|csv` for the same list as a file (all rows, max 5,000; needs `reports.export`, guests need `guests.export`, the activity log `activity_log.view`). Used by reservations, guests, bills, payments, cashier shifts and the activity log. Small lists (settings, staff, hotels) sort and download in the browser (`DataTable` client mode, CSV).

- **Messages** to users: short, plain English, say what to do ("Open your cashier shift (Cashier shift page) before taking cash…").
- **Validation errors** use the field name the screen shows (`ValidationException::withMessages(['amount' => …])`).
- **Comments** explain *why* (the rule, the owner decision), not what the code does.
- **Activity log**: models with `LogsActivity` are logged automatically; important actions call `ActivityLogger::log()`.
- **Style**: `vendor/bin/pint` (CI fixes it on PRs).
