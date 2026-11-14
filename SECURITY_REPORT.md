# HotelMS – security audit (14 Nov 2026)

**Scope:** `main` at `0f12b7d`: the backend (309 routes), the web app, the deployment files (`backend/public/.htaccess`, `deploy-guide/files/`) and CI.
**Checked against:** OWASP Top 10 (2021) and OWASP ASVS 4.0 level 2.
**How:** I read the code, listed every route with its middleware, read the controllers that check access inside, ran `composer audit` and `npm audit`, and searched the code for common risky patterns (raw SQL, mass assignment, `innerHTML`, secrets).
**Not done yet:** a live attack test (pen test) on a running server, and the speed baseline. Both need the app running; speed gets measured before and after each fix in step 2.

> No fixes in this step. Each finding below: **severity**, how someone could misuse it, and the proposed fix.
> Severities: **Critical** = anyone on the internet can get in or take data now · **High** = serious damage needs only a small extra step · **Medium** = worth fixing before go-live (defence in depth) · **Low** = small extra safety.

## Summary

**No critical problems.** The basics are strong:
- hotel isolation is tested on every route;
- money amounts are checked on the server and saved once;
- ID numbers, notes and ID photos are encrypted;
- two-step codes and recovery codes are stored safely;
- exports are safe against Excel formulas;
- no raw SQL is built from user input;
- no known-vulnerable packages: `composer audit` and `npm audit` are both clean.

What's missing is mostly **defence in depth**: the extra layers that limit the damage if something else goes wrong.

| Severity | Count | Findings |
|---|---|---|
| Critical | 0 | – |
| High | 1 | H1 backups are not encrypted |
| Medium | 9 | M1–M9 |
| Low | 11 | L1–L11 |

### Score per area (before → target)
| Area | Now | After the fixes |
|---|---|---|
| Authorisation and hotel isolation | 8/10 | 9.5/10 (a role × route test for every change) |
| Money safety | 9/10 | 9.5/10 |
| Sign-in (brute force, passwords, sessions) | 6.5/10 | 9/10 |
| Account takeover (email, "was this you?") | 6/10 | 9/10 |
| Two-step sign-in | 8.5/10 | 9.5/10 |
| Security headers, cookies, CSRF, CORS | 6/10 | 9/10 |
| Input and output (SQL, XSS, CSV, mail) | 9/10 | 9.5/10 |
| Uploads | 7.5/10 | 9/10 |
| Guest personal data | 8/10 | 9/10 |
| SSRF | 8/10 | 9/10 |
| Secrets | 7/10 | 9/10 |
| Backups | 4/10 | 9/10 |
| Rate limits | 6.5/10 | 9/10 |
| Security event log | 6/10 | 9/10 |
| Dependencies and CI | 8/10 | 9/10 |
| Errors and information leaks | 8.5/10 | 9.5/10 |

---

## High

### H1 – Backups are not encrypted
- **Where:** `app/Services/Backup/Backups.php` writes plain `.sql.gz` files to `BACKUP_PATH`, and copies them to `BACKUP_COPY_PATH` (often another disk or place).
- **What's inside:**
  - every hotel's guests: names, phones, emails;
  - bookings, payments and staff accounts (password hashes);
  - the Telegram bot token (see M6).
  - ID numbers and notes are encrypted with `APP_KEY`. That protection is lost whenever `APP_KEY` sits next to the backup (same server, or a server copy).
- **How it could be misused:** anyone who gets one backup file reads all guest data. That could be a stolen USB or off-site copy, a host employee, or a mistake in the folder permissions. This also breaks the data law (Proclamation 1321/2024) and the owner's W7 rule.
- **Fix:**
  - encrypt every backup while it is written (libsodium `secretstream`, XChaCha20-Poly1305, streamed, so memory stays low) with a **separate `BACKUP_KEY`**, not `APP_KEY`;
  - `--check` and `--restore` decrypt it;
  - Super Admin → System shows red when the key is missing;
  - the off-site copy script copies only encrypted files;
  - the key is printed once by a new command `hotelms:backup-key`. You keep it in your password manager, away from the server.
- **Speed:** backups run at night; encryption adds about 1–2 s per 100 MB.

## Medium

### M1 – No Content-Security-Policy for the web app
- **Where:** `app.html` gets no CSP from `.htaccess`, the Nginx example or Laravel. Only uploaded files (`/storage/`) have one.
- **How it could be misused:** if any XSS ever slips in (a future bug, or an npm package that turns bad), the attacker's script runs with the staff member's session. It can then read and change everything that person can.
- **Fix:** a strict CSP, written to work with the app as it is:
  - `script-src 'self' 'sha256-…'`: the one inline theme script gets a hash;
  - `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'`, `connect-src 'self'`;
  - `img-src 'self' data: blob:` and `frame-src blob:` (the guide PDFs);
  - `style-src 'self' 'unsafe-inline'`, which the charts need for their positions;
  - on the API: `default-src 'none'; frame-ancestors 'none'`.
  - It goes in `.htaccess`, the Nginx example, `docs/hosting.md` and the deploy guide, with a CI test that the hash still matches `index.html`.
- **Speed:** none (one header).

### M2 – Login has no per-account limit and no common-password check
- **Where:** `AppServiceProvider` (`login` limiter: 5 per minute per email + IP, 30 per minute per IP) and `Password::min(8)->letters()->numbers()`.
- **How it could be misused:**
  - from many IP addresses (a botnet), one account can be tried without end;
  - `Password1` or `Hotel2026` pass the rules;
  - staff without two-step sign-in are the weak spot.
- **Fix:**
  - a per-account limit across all addresses: after 10 wrong passwords, wait 15 minutes, growing up to 1 hour, with the same "wrong email or password" message;
  - Hotel Admin and the account owner get an email;
  - a **local list of the 100,000 most common passwords** (a file, no internet, checked in about 1 ms), used for **new** passwords only; existing passwords keep working (see question Q2);
  - minimum 10 characters for new passwords (Q2).

### M3 – The screen lock is only in the browser
- **Where:** `ScreenLock.tsx` keeps "locked" in `localStorage`. The server still answers every request while the screen is locked.
- **How it could be misused:** on a shared reception PC left locked, someone who knows how to use the browser's developer tools can clear the lock and use the receptionist's session (bookings, payments, guest data).
- **Fix:** the server remembers "locked" in the session:
  - while locked, every API call except unlock / PIN switch / sign out answers `423 locked`;
  - the idle time is also checked on the server, using the last request time.
- **Speed:** one session value per request, already loaded.

### M4 – Sessions can live very long
- **Where:**
  - `SESSION_LIFETIME=480` (8 hours idle);
  - "Remember me" keeps a person signed in for about 400 days (Laravel's default remember cookie);
  - there's no absolute maximum.
- **How it could be misused:** a stolen or shared device stays signed in for months. ASVS L2 asks for a new sign-in after at most 12 hours.
- **Fix (needs your OK, Q3):**
  - "Remember me" lasts at most 30 days for staff, and never applies to Super Admin and Hotel Admin;
  - a new sign-in after 12 hours in a row for everyone (the screen-lock PIN stays for short breaks).

### M5 – No overall API rate limit; heavy downloads are not limited
- **Where:**
  - the `api` group has no general throttle;
  - list exports (up to 5,000 rows), `/search`, reports, `/guests?format=xlsx` and the hotel export have no limit of their own.
- **How it could be misused:** one signed-in account, or a stolen session, can pull data quickly or slow the server for every hotel. On shared hosting all hotels share one server.
- **Fix:**
  - a general limit of 300 requests per minute per person (normal use is far below; the dashboard needs about 12 per load);
  - 20 per minute for exports and reports per person;
  - 60 per minute for search.
- **Speed:** one cache counter per request. With the database cache this is one small query, so I'll measure it. If it costs more than 2 ms, the counter goes into the session row instead.

### M6 – The Telegram bot token is stored as plain text
- **Where:** `platform_settings.telegram_bot_token`. The mail password is already encrypted.
- **How it could be misused:** anyone who reads the database or a backup can take over the bot: read hotels' messages and send messages as HotelMS.
- **Fix:** store it encrypted like the mail password, with a one-time migration that encrypts the saved value. Also check that no other setting holds a secret.

### M7 – No "was this you?" emails, and staff email changes are silent
- **Where:**
  - `MeController@updatePassword`, two-step on/off (`SecurityController`) and sign-in from a new device all send no notice to the person. Only the Hotel Admin sees new devices.
  - `StaffController@update` can change a staff member's sign-in email without telling the old address.
  - The Owner's email change notifies the old address but doesn't confirm the new one.
- **How it could be misused:** after taking over an account, an attacker changes the password or the email or turns off two-step sign-in, and the real person never learns why they can't sign in.
- **Fix:**
  - a short "Was this you?" email for: password changed, two-step changed, recovery codes made, new device, sign-in email changed (sent to the **old** address);
  - an email change takes effect only after a link sent to the **new** address is opened (Owner, and staff changed by a manager).
  - Demo hotels never send (as now).

### M8 – No full "role × route" test for changes
- **Where:**
  - `IsolationSweepTest` tries every route as **another hotel** (good);
  - `DemoEveryPageTest` tries every **GET** for every role.
  - No test checks that each **POST/PUT/DELETE** is refused for each role that lacks the permission. Some routes check inside the controller (46 routes, e.g. `/rooms/{id}/status`, `/reports/{key}`, `/payment-proofs`). They are correct today, but nothing stops a future change from losing the check.
- **How it could be misused:** a later change drops a permission check, and a receptionist can, for example, void bills.
- **Fix:**
  - one matrix test that runs every API route against every default role, plus another hotel's user, a Super Admin with each team role, a kiosk-only user and no sign-in;
  - the expected answer comes from the route's permission, or from a short list for the 46 routes that check inside;
  - a new route without an entry fails CI.

### M9 – Security event log has gaps
- **Where:**
  - logged today: failed logins, 403/404/5xx (watchdog), password resets by managers, two-step on/off, recovery code use, PIN switch;
  - **not logged:** successful sign-in, a person's own password change, lockouts and too-many-tries (429), email changes by managers, failed two-step codes, failed PINs at the kiosk, sign-out of other devices.
- **How it could be misused:** an attack or an insider can't be traced afterwards.
- **Fix:**
  - one `security_events` list, kept 1 year, with kind, person, hotel, IP, device and time;
  - a page under Super Admin → Watchdog and Hotel Settings → Activity log;
  - the watchdog flags many lockouts.
  - Money voids and refunds are already in the activity log and stay there.

## Low

| # | Finding | Fix |
|---|---|---|
| L1 | **Login timing:** for an unknown email no password hash is checked, so the answer is a little faster. A patient attacker could tell which emails have accounts. | Always run one hash check (dummy hash) when the email is unknown. |
| L2 | **HSTS only on API answers;** `app.html` and assets from Apache/Nginx have none. No `Permissions-Policy` on static files. | Add HSTS (`max-age=63072000; includeSubDomains`) and `Permissions-Policy` to `.htaccess` and the Nginx example. Preload only after you decide (it is hard to undo). |
| L3 | **CORS uses Laravel's default** (`*`, no credentials). Safe today, but not strict. | `config/cors.php`: only `APP_URL` / `FRONTEND_URL`, credentials on, `api/*` and `sanctum/csrf-cookie` only. |
| L4 | **Uploaded files are not re-encoded.** Payment proofs and ID photos are checked by real file type (JPEG/PNG/WebP/HEIC/PDF, max 8 MB) but kept as they are; a file can carry hidden extra data; a crafted image with huge pixel sizes can freeze a staff browser. PDFs open inline from the app's own address. | Add `Content-Security-Policy: sandbox` and `Content-Disposition` to every file answer; refuse images over 40 megapixels (size read from the header, cheap); re-encode JPEG/PNG/WebP when the server has `gd` (optional on shared hosting – otherwise keep as now). |
| L5 | **Mail server address can be an internal address** (127.0.0.1, 10.x…), and "Send test email" then knocks on internal ports (SSRF). Owner only. | Refuse private, loopback and link-local addresses (after DNS lookup) for `mail_host`; allow `localhost` only when `.env` says so. |
| L6 | **Temporary passwords in invite emails never expire** (forced change at first sign-in, but valid forever until used). | Temporary password valid 7 days; then a manager sends a new one. (Later: a "set your password" link instead of a password in email.) |
| L7 | **Super Admin and Hotel Admin two-step share one switch** (`two_factor_admins`), so turning it off for hotels also turns it off for the HotelMS team. | Two-step always required for the HotelMS team (no switch); the switch then covers Hotel Admins only (Q1). |
| L8 | **No check that `APP_DEBUG` is off and HTTPS is on** in production. | Super Admin → System and the watchdog turn red when `APP_DEBUG=true`, `APP_ENV` ≠ production, cookies not secure, or `APP_URL` not https. |
| L9 | **`/api/v1/health` shows the version** to anyone. | Version only for signed-in HotelMS team; the public answer says ok / down. |
| L10 | **No `/.well-known/security.txt`.** | Add it (Contact: mailto:info@kulmiss.com, Expires one year ahead, Preferred-Languages: en). |
| L11 | **CI has no secret scan.** `composer audit` and `npm audit` already run. | gitleaks on the changed files of each PR (seconds, free); path filters stay. |

## Checked and fine (no change needed)
- **Hotel isolation:** `BelongsToHotel` fails closed; `IsolationSweepTest` tries every route with another hotel's ids; files (payment proofs, ID photos) are checked by hotel and branch; reports are filtered by hotel and branch; search is limited to role and branches.
- **IDOR spot checks:** saved views, alert events, support tickets, staff, room status, payment proofs and drill-downs all filter by owner / hotel / branch.
- **Money:**
  - amounts are `decimal:0,2`, `> 0`, at most 99,999,999 on the server;
  - refunds can't exceed what was paid;
  - discounts are capped by the role's limit;
  - prices never go below 0;
  - money maths uses bcmath strings;
  - saved once (idempotency keys) and one-at-a-time locks on bookings, bills and approvals;
  - voids and refunds need a reason and are logged.
- **Sign-in:**
  - the session id changes at sign-in, and sign-out ends the session;
  - a password change ends the person's other sessions (Sanctum's session check compares the saved password hash; there's no test for this yet, so it gets one in PR 10);
  - a password reset signs out all devices;
  - the forgot-password answer is the same for every email;
  - the kiosk PIN is limited per hotel (10 per minute, 30 per hour), and the screen-lock PIN is limited too;
  - PIN switch is never allowed for Hotel Admins or for people with two-step sign-in;
  - device approvals work.
- **Two-step sign-in:**
  - the secret is encrypted;
  - recovery codes are stored as hashes (and encrypted);
  - a code can't be used twice;
  - 10 wrong codes means a 15-minute wait;
  - email codes allow 5 tries and expire.
- **Input and output:**
  - no mass assignment (every model has `Fillable`, nothing uses `$request->all()`);
  - raw SQL only uses fixed lists or numbers;
  - React escapes everything, and the only raw HTML is the Developer docs, cleaned by the server;
  - emails escape (`{{ }}`, no `{!! !!}`);
  - CSV and Excel cells that start with `= + - @` get an apostrophe;
  - Excel import blocks network entities and size bombs (40 MB unpacked).
- **Uploads:** checked by real file type, no SVG, random names, private disk for proofs and ID photos, ID photos encrypted, `nosniff`.
- **Guest data:**
  - ID number and notes are encrypted, with an exact-match search hash, so search stays fast;
  - ID shown masked in lists;
  - ID photos have a retention rule;
  - a closed hotel is deleted after 90 days;
  - no personal data found in log calls.
- **Cookies and CSRF:** session cookie `HttpOnly`, `Secure` in production, `SameSite=Lax`; the device cookie is the same; Sanctum checks the XSRF token.
- **Secrets:** the mail password is encrypted; `.env` is not in Git; no keys or tokens found in the code; the package leaves out `.env` and storage.
- **Errors:** `APP_DEBUG=false` in the production example; JSON errors without traces; `expose_php = Off` in the server examples.
- **Dependencies:** `composer audit` clean, `npm audit` clean (14 Nov); no unused packages found (Heroicons kept for the print pages, as you decided).

## Progress (step 2 – owner OK and answers Q1–Q6 "yes", 14 Nov)
| PR | Findings | Status |
|---|---|---|
| S1 Encrypted backups | H1 | done – `BACKUP_KEY`, `.sql.gz.enc`, no backup on a live server without the key, upload copies encrypted, `--decrypt`, System + watchdog warnings |
| S2 Security headers | M1, L2, L3, L10 | done – strict CSP on the app page (hash checked in CI, checked in headless Chrome on 16 screens incl. prints: no violations), CSP on API answers, HSTS 2 years + Permissions-Policy on every file, strict CORS, `security.txt` |
| S3 Sign-in hardening | M2, L1 | done – per-account pause after 10 wrong passwords (15 → 30 → 60 min, same answer for unknown emails, email to the person), dummy hash for unknown emails, new passwords ≥ 10 characters and not common (local list), weak old passwords keep working with a note (Q2) |

## Proposed fix PRs (step 2, after your OK)
In order of importance. Each one is small, with tests, and speed measured before and after (page load, API time, first download).

| # | PR | Findings | Size |
|---|---|---|---|
| 1 | Encrypted backups with a separate key | H1 | Medium |
| 2 | Security headers: CSP, HSTS, Permissions-Policy, CORS, security.txt (app + `.htaccess` + Nginx + guide) | M1, L2, L3, L10 | Small |
| 3 | Sign-in hardening: per-account lockout, common-password list, timing fix | M2, L1 (+ Q2) | Medium |
| 4 | Server-side screen lock and session limits | M3, M4 (+ Q3) | Small–medium |
| 5 | "Was this you?" emails and confirmed email changes | M7, L6 | Medium |
| 6 | Security event log | M9 | Medium |
| 7 | Rate limits | M5 | Small |
| 8 | Secrets and settings checks: Telegram token encrypted, mail host SSRF block, production checks, health | M6, L5, L8, L9 | Small |
| 9 | Uploads: sandbox header, pixel limit, re-encode with gd | L4 | Small |
| 10 | Role × route matrix test + gitleaks in CI | M8, L11 | Medium |
| 11 | Two-step: always on for the HotelMS team | L7 (+ Q1) | Small |

After PR 5 and PR 6, the privacy texts need two lines (security emails, security log kept 1 year). Your legal texts aren't written yet (W13), so I'll add those lines to your to-do list.

## Questions for you (each with my recommendation)
1. **Two-step for hotel owners (Hotel Admins):** today it is **required by default** (your W7 decision), through the same switch as the HotelMS team.
   *Recommend:* keep it **required** for Hotel Admins, and make it **always required (no switch)** for the HotelMS team (L7).
2. **Passwords:**
   - *Recommend:* new passwords need **at least 10 characters** and must not be on the common-password list.
   - **Existing passwords keep working.** People are asked to change a weak one at their next sign-in, but not forced.
3. **Sessions:**
   - *Recommend:* "Remember me" at most **30 days** for staff; **off** for Super Admin and Hotel Admins.
   - A new sign-in after **12 hours** for everyone.
   - This changes how long people stay signed in, so it needs your OK.
4. **Encrypting phones or names:** *Recommend: no.* Search by phone and name would get much slower (it can't use the database index). ID numbers, notes and ID photos are already encrypted.
5. **Accountant and full guest IDs:**
   - Today the Accountant role (`guests.view`) can open a guest's page with the full ID number and the ID photo.
   - *Recommend:* full ID and photo only for roles that check guests in (`guests.create_edit`); everyone else sees the masked number.
   - This changes what the Accountant sees, so it needs your OK.
6. **Backup key:** *Recommend:*
   - backups are refused without the key on a live server, with a red warning in System;
   - you keep the key in your password manager;
   - the old unencrypted backups are deleted after 30 days, as usual.
