# Data model

MySQL 8.4 (MariaDB 10.6+ also works). All times are stored in **UTC**; dates of nights are **hotel-local dates**.
Money is `decimal(14,2)` with a currency code. Tables marked **H** have `hotel_id` and their model uses `BelongsToHotel`
(automatic hotel scope). The full history is in `backend/database/migrations/`; the approved design is `database-schema.md`
in the claude.ai Project.

## Platform (HotelMS company)

| Table | Important columns | Notes |
|---|---|---|
| `booking_requests` | hotel_id, branch_id, room_type_id, check_in, check_out, adults, children, name, phone, email, note, status (new/accepted/declined), reservation_id, handled_by/at (no network address, owner 14 Nov) | From the public booking page `/h/{hotels.booking_slug}` |
| `plans` | code, name, price_per_room, minimum_monthly, yearly_free_months, max_rooms, max_branches, max_staff, max_storage_mb, modules, is_custom, is_public, is_recommended | Free / Starter / Pro / Business (flat price: 0 per room, minimum = price). Old Basic / Standard / Pro / Custom are "(classic)": kept for their hotels, not offered (`is_public` false). Plan features are plan-level rows in `feature_switches` |
| `hotels` | name, status (trial, active, grace, suspended; a closed hotel is soft-deleted), plan_id, trial_ends_at, subscription_ends_at, timezone, default_currency, is_demo, legal_name, tin, vat_number, setup_steps, setup_completed_at, signup_status, signup (owner name, rooms, mode, terms accepted), signup_decided_at/by, signup_reason | Closed hotels are purged after 90 days. `users.email_verify_token` (sha256) for the sign-up link |
| `subscription_payments` | hotel_id, amount, months, period_start/end, method, reference | Money a hotel paid HotelMS |
| `hotel_invoices` | hotel_id, number, period, rooms, amount, status, due_date | HotelMS bills to hotels |
| `platform_settings` | key, value (JSON) | Branding, support contacts, Telegram bot, SMTP (password encrypted), demo… (`PlatformSetting::read/write`) |
| `feature_switches` | key, level (everyone / plan / hotel), target_id, value, starts_at, ends_at | Super Admin switches (`config/switches.php`) |
| `banners` | message, tone, hotel_ids, plan_ids, starts_at, ends_at | Messages shown at the top of the app |
| `releases` | version, title, body, status | "What's new" |
| `support_requests`, `support_messages` | subject, priority, status, assigned_to; body, kind | Help → Ask us ↔ Super Admin → Support |
| `hotel_notes`, `data_access_logs` | | Super Admin notes per hotel; every look at a hotel's live data, with the reason |
| `impersonation_logs` | super_admin_id, hotel_id, impersonated_user_id, reason, started_at, ended_at | "Log in as hotel" |
| `watch_events`, `watchdog_findings` | kind, path, status; fingerprint, check, severity, times, resolved_at, muted_until | Super Admin watchdog |

## People and access

| Table | Important columns | Notes |
|---|---|---|
| `users` | hotel_id (null for the HotelMS team), role_id, is_super_admin, platform_role, status, must_change_password, temporary_password_expires_at (7 days), pending_email + pending_email_token (sha256) + pending_email_expires_at (a new email waiting for its link), attendance_pin, two_factor_*, calendar and language (own choice; null = hotel default) | **Not** hotel-scoped (sign-in is platform-wide) |
| `branch_user` | branch_id, user_id | Branches a person works in (Hotel Admins: all) |
| `roles` **H**, `role_permissions` | key, name, is_locked, max_discount_percent; permission | Default roles from `config/permissions.php` |
| `user_devices` | user_id, hotel_id, token_hash, name, ip, status (active, pending, blocked, signed_out) | Devices; "approved devices only" |
| `sessions` | | Database sessions (shared hosting) |
| `activity_logs` | hotel_id, user_id, event, subject, description, properties, ip | Who did what; never deleted by users |
| `security_events` | kind, user_id, hotel_id, email, ip, device, detail, created_at | Security event log (SECURITY_REPORT M9): sign-ins, failures, pauses, password / email / two-step changes, 403 / 429, voids, refunds; kept 1 year (pruned daily 03:50), max 60 rows a minute per person or address |
| `telegram_links` **H** | user_id, chat_id, code | A person's Telegram for reports and alerts |
| `app_notifications` **H** | user_id, title, body, link, urgent, read_at | The bell |

## Hotel set-up

| Table | Important columns | Notes |
|---|---|---|
| `branches` **H** | name, code, check_in_time, check_out_time, status | |
| `hotel_settings` **H** | key, value | Booking rules, invoice and security settings (`HotelSettings` defaults) |
| `hotel_modules` **H** | module, is_enabled | cashier_close, housekeeping, shifts, attendance |
| `hotel_currencies` **H**, `exchange_rates` **H** | code, is_default; from, to, rate (14,6), effective_date | |
| `taxes` **H** | rate, applies_to (rooms, extras, all), is_inclusive | |
| `payment_methods` **H** | name, type (cash, mobile_money, bank_transfer) | Cash goes to the cashier shift |
| `charge_types` **H** | name, default_price | Laundry, food… |
| `cancellation_policies` **H** | free_until_hours, penalty_type (nights, percent, fixed), penalty_value, applies_to_no_show, is_default | |
| `room_types` **H** | branch_id, base/max capacity, nightly_price, day_use_price, hourly_price, amenities | |
| `rooms` **H** | branch_id, room_type_id, number, floor, status (available, occupied, dirty, cleaning, maintenance, out_of_order) | |
| `guest_types` **H**, `rate_rules` **H** | rule_type (weekend, season, holiday, guest_type; long_stay, early_bird, last_minute), dates, days_of_week, min_nights, days_before, min_stay, adjustment, value, priority | Prices |
| `ical_feeds` **H**, `ical_blocks` **H** | feeds: branch_id, room_id or room_type_id, name, url (encrypted), is_active, last_synced_at, last_status, last_error, events_count, conflicts; blocks: feed, room_id / room_type_id, uid, starts_on, ends_on, starts_at, ends_at (UTC), summary | Calendar sync; blocks hold rooms like bookings |
| `holidays` **H** | name, date, kind (fixed, calculated, expected, own), is_active | The hotel's holiday list ("holiday" rules) |
| `imports` **H** | type, rows_count, created_ids, undone_at | Excel/CSV imports (undo possible) |
| `number_sequences` **H** | key, last | Booking codes and bill numbers per year |

## Guests, bookings and money

`reservations.open_stay` (boolean): a walk-in with no end date; the stay always ends at the next check-out time and `stays:roll-open` moves it on by one night each day (workflows.md Q16).

| Table | Important columns | Notes |
|---|---|---|
| `guests` **H** | names, phone, email, nationality, id_type, id_number (encrypted), id_number_hash (search), is_blacklisted | Shared by all branches. List shows masked ID numbers |
| `guest_id_photos` **H** | guest_id, path (encrypted file), mime | Optional ID/passport photo |
| `reservations` **H** | branch_id, code, guest_id, source, stay_type (nightly, day_use, hourly), check_in_at, check_out_at, status (pending, confirmed, checked_in, checked_out, cancelled, no_show), policy_snapshot, subtotal, discount, tax_total, total, deposit_required, paid_total, pay_later_* | The booking |
| `rooms` extra | ical_token (sha256 of the export secret), ical_secret (encrypted) | Export link; hidden from JSON |
| `reservation_rooms` **H** | room_type_id, room_id (null until assigned), check_in_at, check_out_at, **blocked_until** (check-out + cleaning gap), actual_check_in/out_at, total_amount, price_override (per night, set by hand), status | One per booked room; availability works on these |
| `reservation_room_rates` | date (hotel-local), label (Night, +N hours…), amount, rate_rule_id | The price of each night |
| `reservation_guests` | | Extra people in a room |
| `invoices` **H** | number, reservation_id, reservation_room_id (split bills), subtotal, discount_total, tax_total, total, paid_total, balance, status (open, partially_paid, paid, outstanding, void), outstanding_approved_*, voided_* | The bill; opens at check-in |
| `invoice_items` **H** | type (room, extra, early_check_in, late_check_out, early_check_out, cancellation_fee, discount), quantity, unit_price, amount, is_waived, service_date | Lines |
| `invoice_item_taxes` | tax name, rate, is_inclusive, amount | Taxes per line |
| `payments` **H** | reservation_id, invoice_id, payment_method_id, cashier_session_id, type (deposit, payment, refund), amount, currency, exchange_rate, amount_in_base, reference, paid_at, received_by | |
| `payment_proofs` **H** | payment_id, path, mime | Optional photo/PDF of a payment |
| `approvals` **H** | kind, payload, status, decided_by | Things that wait for a manager |

## Optional modules

| Table | Notes |
|---|---|
| `cashier_sessions` **H**, `cashier_session_balances` | A person's cash shift; per currency: opening, expected, counted, difference |
| `housekeeping_tasks` **H** | room_id, type (cleaning, inspection, maintenance), priority, status, assigned_to, started/completed |
| `shifts` **H**, `shift_assignments` **H** | Shift templates; the roster |
| `attendances` **H** | clock_in_at, clock_out_at, method (self, kiosk_pin, manual), status (present, late, absent, leave) |

## Reports and automation

| Table | Notes |
|---|---|
| `report_targets` **H** | Monthly revenue / occupancy targets |
| `saved_views` **H**, `custom_reports` **H** | Saved filters; custom report builder |
| `report_schedules` **H** | Reports sent by email/Telegram |
| `alert_rules` **H**, `alert_events` **H** | Smart alerts |
| `automation_settings` **H**, `automation_rules` **H**, `automation_firings` **H** | Ready-made automations (on/off + options), the rule builder, and "already done" marks |

## Saved-once keys

`idempotency_keys` (not hotel-owned; per person): `user_id`, `key` (the app's Idempotency-Key), `endpoint` (method + path), `request_hash` (sha256 of the body), `status` (processing / done), `response_status`, `response_body`, timestamps. Unique (`user_id`, `key`). Kept 24 hours, deleted after 48 by the scheduler. Used by the `Idempotent` middleware.
