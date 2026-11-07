# Recipes: how to make common changes

Each recipe lists every place to touch. Finish every change with: tests, docs (this folder), staff guide (if staff see it),
a branch + PR (never push to `main`).

## Add a hotel setting (e.g. a new booking rule)

1. Default value: `backend/app/Services/HotelSettings.php` (e.g. `BOOKING_DEFAULTS['my_rule'] => …`). No migration: settings are key/value.
2. Validation: `Settings/HotelSettingsController::updateBookingRules` (use `sometimes` so older screens still save).
3. Use it: `$this->settings->group(HotelSettings::BOOKING_DEFAULTS)['my_rule']` (inside a hotel context).
4. Screen: `frontend/src/pages/settings/SettingsSections.tsx` (the matching section), text in `en.json` under `settings.rules`.
5. Settings search: add the field to `lib/settingsSearch.ts` if people should find it.
6. Test: feature test that changes the setting and checks the rule.

## Add a hotel permission

1. `backend/config/permissions.php`: add `'area.action' => [$BM, …]` in the right group (default roles that get it).
2. Existing hotels: a migration that grants it to the default roles (see `2026_11_13_000001_grant_report_permissions_to_default_roles.php`).
3. Name and help text: `en.json` → `permissionNames.area.action`.
4. Route: `->middleware('permission:area.action')`, or `$user->hasPermission()` inside.
5. Frontend: `can('area.action')` to hide buttons; menu items have `permission` in `app/navigation.ts`.
6. Staff guide: a topic with `perm: 'area.action'` if it is something staff do.

## Add a Super Admin switch

1. `backend/config/switches.php`: key, type, group, default.
2. Check it: `->middleware('switch:feature.x')` or `app(Switches::class)->on($hotel, 'feature.x')`.
3. Frontend: `me.switches['feature.x']` (sent by `/me`).

## Add a table (hotel data)

1. Migration in `database/migrations/` with `hotel_id` (+ `branch_id` if it belongs to a branch), indexes on what you filter by.
2. Model with `use BelongsToHotel;` (and `LogsActivity` if changes matter), `#[Fillable([...])]`, casts (money as string, dates).
3. Controllers check the branch of every record (`$user->accessibleBranches()`).
4. `IsolationSweepTest`: make sure a row of the other hotel exists for your route, so the sweep proves isolation.
5. Hotel closing/export: add the table to `HotelExport` (download) and check `HotelClosing` (files).
6. Update [data-model.md](data-model.md). Remind the owner to run `php artisan migrate` after merging (local: `docker compose exec backend php artisan migrate`).

## Add an API endpoint

1. Route in `routes/api.php`, in the right group (hotel routes inside `hotel.writable`/`emergency` unless read-only), with `permission:`/`module:`/`switch:`;
   money actions get `idempotent` and `one.at.a.time:<kind>`.
2. Controller: validate, check permission and branch, call a **service**, return a presenter/JSON (money as strings, times with `HotelClock::iso`).
3. Messages in plain English with the field name the screen uses.
4. Feature test: happy path, not allowed (403), another hotel (404), wrong input (422).
5. Update [api.md](api.md) if it is a new group or convention.

## Add a screen

See [frontend.md → Writing a screen](frontend.md#writing-a-screen).

## Add a report

1. Numbers: a method in `Reports/Metrics` (or `Insights`) using indexed columns; hotel and branch filters always.
2. Register it: `ReportController::REPORTS` (key → permissions) and the `match` in `show()`; CSV/XLSX columns if exportable.
3. Frontend: `pages/reports/ReportsPage.tsx` list + a component; text in `en.json` (`reports.*`).
4. Test with known data (`ReportsTest`); the speed test times it automatically (keep it under the limits).

## Add an automation

1. `config/automations.php`: key, group, default, `to` (permissions), options, module.
2. Event-based: `Automation/AutomationEvents` (called from the place where it happens); timed: `Automation/AutomationSchedule`.
3. Never twice: `automation_firings` (see existing ones). Respect quiet hours and the switch `automation.<key>`.
4. Text: `en.json` → `automations.items.<key>` (name, what). Test in `AutomationsTest`.

## Add a smart hint

`config/smart.php` (key) + a method in `Smart/SmartHints` returning `{key, text, why, tone, link, explain}` with
`self::explain(facts, how, do)`, then show it with `<SmartHints>` on the screen. Test in `SmartHintsTest`.

## Add an email or Telegram message

Email: a `Mailable` in `app/Mail` + a view in `resources/views/mail` (uses the `Branded` concern). Send through `Notify/Notifier`
(skips the demo hotel). Telegram: `Notify/Telegram::send($chatId, $text)`; the person's chat: `chatFor($user)`.
Tests: `Mail::fake()`, `Http::fake(['api.telegram.org/*' => …])`.

## Change staff words or add a guide topic

- Words on screens: `frontend/src/i18n/locales/en.json`. Keep the easy words (Arriving, Leaving, Don't charge, Cancel bill).
- Guide: add `{ key, perm, module }` to `lib/guides.ts` and `topics.<key>` (title + steps) to `locales/en.guide.json`.
  Very easy English: short sentences, one action per step, the exact button names. `test/guides.test.tsx` checks every topic has text.

## Release to a server

See [hosting.md](hosting.md): `./deploy/package.sh`, upload, `php artisan migrate --force && php artisan optimize`.
