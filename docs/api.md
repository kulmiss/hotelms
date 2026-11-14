# API

All routes are in `backend/routes/api.php`, under **`/api/v1`**, JSON only. This page explains the conventions and
lists the route groups; the file itself is the exact, complete list (with each route's permission).

## Conventions

| Topic | Rule |
|---|---|
| **Auth** | Sanctum **cookie sessions** (same domain). The app first calls `GET /sanctum/csrf-cookie`, then sends `X-XSRF-TOKEN` on every change. No tokens in the browser. |
| **Sign-in** | `POST /auth/login` → `{message}` or `{two_factor: "app"\|"email"}` → `POST /auth/two-factor {code}` (6 digits or a recovery code) |
| **Who am I** | `GET /me`: user, hotel (modules, setup), branches, role, permissions, platform role, impersonation, switches, emergency, security |
| **Hotel** | Always the signed-in person's hotel (never a `hotel_id` in the request). Records of another hotel answer **404**. |
| **Branch** | Lists take `?branch_id=`; without it, all branches the person may see |
| **Errors** | `422` `{message, errors: {field: [text]}}` – plain English, the field the screen shows; `403` not allowed; `404` not found (or another hotel's) – a missing record answers `{message: "Not found. …", code: "not_found"}`, never a class name; `423` locked (suspended/emergency, or `code: screen_locked`) |
| **Money** | Strings with 2 decimals (`"4662.50"`) and a currency code |
| **Dates** | ISO 8601 with the hotel's offset (`2026-11-12T14:00:00+03:00`); plain dates `YYYY-MM-DD` are hotel-local |
| **Money actions** | Send `Idempotency-Key: <uuid>`; a repeat gets the first answer (`Idempotent-Replay: true`). Only successful answers are kept. |
| **One at a time** | Changes to one booking or bill wait for each other (`one.at.a.time`) |
| **Overpaying** | A payment above what is owed answers `422 errors.confirm_overpay`; send again with `confirm_overpay: true` (in `payment` for check-in/out/walk-in) |
| **Pages** | Long lists use Laravel pages: `{data, current_page, last_page, total, …}` (`?page=`, sometimes `?per_page=` up to 100) |
| **Exports** | Reports: `?format=csv` or `xlsx` (formula-safe cells) |

## Route groups

Public: `GET /health`, `GET /branding`, `GET /branding/manifest`, `POST /auth/login`, `/auth/two-factor`, `/auth/forgot-password`,
`/auth/reset-password`, `/auth/confirm-email {token}` (the link sent to a new sign-in email), `/demo/login` (only when the public demo is on).
Sign-in limits: 10 wrong passwords for one email pause it 15 → 30 → 60 min (`422`, same for unknown emails); a sign-in ends after
12 hours (`401 code: session_expired`, not for "Remember me" – staff only, 30 days); a locked screen answers `423 code: screen_locked`
to everything but `POST /auth/unlock`, `/auth/pin-switch`, `/auth/logout`, `/auth/lock` and `GET /me`.
`PUT /me/email` and `PUT /staff/{id}` answer `email_change: "pending"` when the new email waits for its link.

Signed in (everyone): `/me`, `/me/password`, `/me/email` (platform Owner), `/telegram` (status, link, test, unlink),
`/impersonation/stop`, `/setup`, `/search`, `/smart/{place}`, `/whats-new`, `/notifications`, `/support` (Help → Ask us),
`/me/security/*` (two-step, recovery codes, PIN, own devices).

| Area | Routes | Permission |
|---|---|---|
| Setup walkthrough | `/setup/steps/{step}`, `/setup/finish` | Hotel Admin |
| Roles and staff | `/roles`, `/permissions`, `/staff`, `/devices` | `roles.manage`, `staff.view`, `staff.manage` |
| Guests | `/guests`, `/guests/{id}/blacklist`, `/guests/{id}/id-photo` | `guests.view`, `guests.create_edit`, `guests.blacklist` |
| Bookings | `/reservations` (list, calendar, lookups, availability, quote, show, registration card, cancellation terms, payments), create, update, pay-later, cancel, no-show | `reservations.*`; payments check `payments.take` / `payments.refund` inside |
| Front desk | `/front-desk/board`, `/front-desk/today`, `/front-desk/walk-in(/rooms)`, `/reservations/{id}/check-in`, `/check-out`, `/rooms/{stay}/move`, `/extend`, `/approve-balance`, `/rooms/{id}/status` | `frontdesk.*`, `checkout.approve_balance`, `rooms.change_status` / `rooms.maintenance` |
| Quick charges | `/front-desk/quick-charges` (rooms with a guest + quick buttons), `POST /front-desk/rooms/{room}/charges`, `DELETE /front-desk/charges/{item}` (undo, 10 min, own) | `charges.room` |
| Bills | `/invoices` (list, show, items, payments), void ("cancel bill"), split, buyer (company + TIN), `/payments` (`from`, `to`, `type`, `branch_id` – one of your own branches), `/charge-types`, `/payment-proofs` | `invoices.*`, `payments.*` |
| Modules | `/cashier/*`, `/housekeeping/*`, `/shifts/*`, `/attendance/*` | `module:*` + `cashier.*`, `housekeeping.*`, `shifts.*`, `attendance.*` |
| Reports | `/dashboard` (today; `next_days` = booking pace for 14 days), `/dashboard/trend?from&to&branch_id` (per day arrivals, departures, rooms sold, occupancy; revenue, room revenue, ADR, RevPAR and money taken ONLY with `dashboard.view_revenue` — never sent otherwise), `/reports/{key}`, `/reports/daily|forecast|targets|audit|my-shift|my-work|accounting|drilldown`, `/saved-views`, `/custom-reports`, `/report-schedules`, `/alert-rules`, `/alert-events` | `reports.*` (per report), `alerts.manage` |
| Settings | `/settings/hotel|branches|currencies|taxes|payment-methods|exchange-rates|charge-types|booking-rules|cancellation-policies|room-types|rooms|import|guest-types|rate-rules|price-preview|modules|automations|automation-rules|attendance|security|export` | `settings.*`, `automations.manage` (reading `/settings/currencies` also works with `settings.exchange_rates`, so a Branch Manager can update rates) |
| Other | `/activity-log`, `/subscription`, `/approvals` | `activity_log.view`, `subscription.view`, `approvals.decide` |
| **Super Admin** `/admin/*` | dashboard, hotels (create, update, trial, suspend, activate, close, reopen, impersonate, users reset password, demo reset, notes, live data, invoices, payments), plans, switches, banners, support, system, activity, releases, content, team, settings (+ test email, logo), reports, watchdog, docs (`/admin/docs`, `/admin/docs/{name}`, the guide PDFs `/admin/docs/pdf/{name}`) | `platform:*` (see `config/hotelms.php`) |

Every route is tried as another hotel and as a one-branch user by `tests/Feature/Tenancy/IsolationSweepTest.php`.
