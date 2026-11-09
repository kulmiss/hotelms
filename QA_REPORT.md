# HotelMS – full QA pass (8 Nov 2026)

Report only: nothing was fixed during the pass.

## 1. What was tested

- **Branch / commit:** `ui/shadcn-admin` @ `343fde7` (everything up to #107: P2–P17, #104 package cleanup, #105 phone fixes, P19 collapsible filters). Checked in the owner's browser at `localhost:5173`. The P19 filters were visible there, which confirms the commit.
- **Hotel:** only **Sheger Demo Hotel** (id 14, `is_demo`). No other hotel was opened or changed.
- **How each role was used:** Super Admin "log in as hotel" (support access) with the reason *"Full QA pass (owner request)"*. I typed no passwords.
- **Demo-only safety:** real emails, Telegram and automation messages cannot go out from the demo.
  - Demo hotels never send email, Telegram or automation messages (`Notifier` skips `is_demo`).
  - New staff get no email in the demo (`email_sent: false`).
  - Mail in development goes to Mailpit.
  - There is no payment gateway, SMS or outgoing webhook in the code.
- **Demo data I created** (the owner can re-seed to clean up):
  - guest "QA Tester"
  - walk-in RES (room 305 → 401, checked out)
  - a booking (+11 nights, cancelled)
  - a no-show on RES-26-04082
  - a check-in of RES-26-04703
  - a late check-out of RES-26-04545 (balance approved)
  - a laundry charge, a 5% discount and a 100 refund on its bill
  - one closed cashier shift (Hana)
  - one cleaning task (room 106)
  - room 208 set out of order and back
  - staff "QA Temp Staff 2" (disabled)
  - two QA roles (deleted)
  - a clock-in (Selam)
  - settings were changed and changed back

### What I could NOT test, and why

| Item | Why |
|---|---|
| Password change, two-step setup, sign out / sign in, screen-lock unlock, kiosk PIN | They need typing a password or PIN. Rule: Claude never types passwords. |
| Session timeout | It would end the support session mid-pass. The 401 → sign-in handling exists in `app/providers.tsx`. |
| Slow network (throttling) | The browser pane cannot throttle. I only checked the offline banner (`offline` event) ✓. |
| Switching language | Only English exists (`en.json`). The Amharic files are not made yet. |
| Switching branch mid-flow | The demo staff work in one branch (Bole). |
| Approvals approve / reject | The demo has no requests. They are made by automations (price suggestions, device approvals). A manager's refund went through directly, as designed. |
| New hotel (Super Admin) | It would create a real, non-demo hotel. |
| ID photo / payment-proof upload | They need a file from the computer. |
| Print previews (invoice, registration card) | Not compared picture by picture. The page files and print CSS are unchanged against `main` (`git diff` empty), and the paper uses its own `bg-white text-black`. |
| Tap-target check on 768 / 1024 tablets | The pane emulates touch only below 768px. Tap targets were checked at 375px. |
| Running docker / npm / artisan commands from the docs | My workspace has no Docker and no npm registry. I checked that every artisan command named in the docs exists, and `deploy/package.sh` passes a syntax check. CI runs lint, typecheck, tests and build on every PR (green on #106 / #107). |
| Staff guide pictures | They need the app running in the picture tool. They are all from the old look (list in §5). |
| Dashboards "new" (P18) | Not built yet (only the proposal step had started). |

**How the screens were checked:** a script opened every route at 375 / 768 / 1024 / 1280 / 1920px, in light and dark mode. That is 61 hotel URLs (every route pattern, tabs and settings sections included) plus 16 Super Admin routes, 10 runs each. For every screen it recorded:
- sideways scroll
- text contrast under 4.5:1 (computed colours, mixed with the background)
- tap targets under 44px (on touch, at 375px)
- raw translation keys on screen or in labels
- console errors
- failed or slow (> 2 s) API calls
- load time
- where the browser was sent (redirects)

The script was confirmed with screenshots for every problem.

## 2. Role × screen access

**Roles in the demo hotel:**
- Hotel Admin – Selam Bekele, `demo.admin@hotelms.demo`
- Branch Manager – Dawit Haile, `demo.manager@…`
- Receptionist – Hana Tesfaye, `demo.reception@…`
- Housekeeping – Mulu Girma, `demo.housekeeping@…`
- Accountant – Yonas Alemu, `demo.accountant@…`
- Restaurant / Bar – Kidist Mamo, `demo.restaurant@…`
- Super Admin – the platform owner, platform role `owner`

The demo password is the documented demo one, not typed by me.

✓ = opens · ✗ = blocked (sent to the start page) · ⚠ = see note

| Screen (URL) | Admin | Manager | Reception | Housekeeping | Accountant | Restaurant | Super Admin |
|---|---|---|---|---|---|---|---|
| Dashboard `/` | ✓ | ✓ money | ✓ no money | ⚠1 | ✓ money | → `/charge` | → `/admin` |
| Room board, walk-in, front desk mode | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Room calendar `/rooms/:id/calendar` | ✓ | ✓ | ✓ | ✗ | ✓ | ✗ | ✗ |
| Charge a room | ✓ | ✓ | ✓ | ✗ | ✗ | ✓ (start page) | ✗ |
| Bookings list / detail | ✓ | ✓ | ✓ | ✗ | ✓ | ✗ | ✗ |
| New / edit booking | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Guests list / detail · new | ✓ | ✓ | ✓ | ✗ | ✓ · ✗ | ✗ | ✗ |
| Bills, bill, print | ✓ | ✓ | ✓ | ✗ | ✓ | ✗ | ✗ |
| Payments list (API) | ✓ | ✓ | ✗ 403 | ✗ | ✓ | ✗ | ✗ |
| Cashier | ✓ | ✓ | ✓ own shift | ✗ | ✓ all | ✗ | ✗ |
| Housekeeping | ✓ | ✓ | ✓ | ✓ own tasks | ✗ | ✗ | ✗ |
| Shifts, attendance, kiosk | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Approvals | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Reports | ✓ | ✓ | ✓ my shift | ✓ my work | ✓ | ⚠2 | ✗ |
| Staff, devices | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Roles, activity log | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Settings: hotel profile / subscription | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Settings: taxes (money) | ✓ | ✗ | ✗ | ✗ | ✓ | ✗ | ✗ |
| Super Admin `/admin/*` | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ |

**Checked for every role:**
- **Sidebar:** shows exactly the allowed screens.
- **⌘K:** lists only allowed pages; for example, Reception gets nothing for "setting" or "staff". There is no search box at all for Housekeeping and Restaurant, by design.
- **Money widgets:** only for roles with `dashboard.view_revenue` (Admin, Manager, Accountant).
- **Bill actions:** Reception sees no Refund choice and gets 403 from the API. Housekeeping gets 403 on bills.
- **Server checks:** the backend blocks every hidden screen's data. 19 API probes per role gave 403 where the role lacks the permission, with **no data leak**.
- **Super Admin and hotel roles are fully apart:** the Super Admin gets 403 on all hotel APIs and is sent to `/admin` from hotel URLs. Hotel roles are sent away from `/admin/*`.

**Notes:**
- ⚠1 Housekeeping's start page asks for `/dashboard` and gets **403** every time. It still shows its own "Rooms to clean / Your work" card (small, §4 s1).
- ⚠2 Restaurant / Bar can open `/reports` by URL (not in the menu). It shows "Your role has no reports"; no data (small).
- **For every role, a blocked URL silently goes to the start page.** There is no "You don't have access" page (medium, §4 M1).

## 3. Scenarios

Done through the app's own API as the role named, with the business rules checked in the answers. The screens for these flows were also opened by the size / mode crawl. Pass = the expected result, including the expected refusals.

| Scenario | Role | Result |
|---|---|---|
| Guest: create (long last name), edit, search, history | Reception | ✓ |
| Cashier: open shift (500 ETB) | Reception | ✓ |
| Cash without an open shift is refused ("Open your cashier shift…") | Reception | ✓ rule |
| Walk-in: less than the first night is refused ("Take at least 2875.00 ETB") | Reception | ✓ rule |
| Walk-in with full first night, **sent twice at once with the same key → one stay** | Reception | ✓ |
| Charge a room (quick button Breakfast) | Reception | ✓ |
| Part payment (telebirr 300) | Reception | ✓ |
| Extend +1 night | Reception | ✓ |
| Move room 305 → 401 | Reception | ✓ |
| Early check-out: overpaying is refused ("1437.50 ETB more than the guest owes"), then pays 1540 → bill paid, balance 0, room 401 dirty | Reception | ✓ |
| New booking (+10 days); check-out before check-in refused | Reception | ✓ |
| Change dates; change / assign room | Reception | ✓ |
| Deposit → confirmed | Reception | ✓ |
| Cancel (free) → refund due shown | Reception | ✓ |
| A booking in the past is refused | Reception | ✓ rule |
| No-show on a late arrival | Reception | ✓ |
| Check-in into a dirty room is refused; room dirty → cleaning → available; then check-in | Reception | ✓ |
| Setting a room "occupied" by hand is refused | Reception | ✓ |
| Overdue check-out without payment refused; manager approves the balance → checked out with a **late check-out fee** (2090.00) | Reception / Manager | ✓ |
| Bill: extra charge, discount 5% ✓, discount 50% refused (role limit), part payment, split (refused: one room) | Reception | ✓ |
| Refund: Reception 403 (not allowed); Manager refund 100 ✓ | Reception / Manager | ✓ |
| Cashier close: no count refused; counted 4915 = expected (500 + 2875 + 1540) → difference 0.00 | Reception | ✓ totals match |
| Housekeeping: manager assigns task → housekeeper starts (room cleaning) → done (room available); room out of order and back | Manager / Housekeeping | ✓ (there is no "inspected" step in the app) |
| Reports: all 9 reports, 1 week and 1 year, plus Excel for each; forecast, daily, targets | Admin | ✓ (every one < 2 s; forecast / daily / targets / dashboard 0.2–0.5 s) |
| Settings: booking rules and hotel profile changed, saved and undone | Admin | ✓ |
| Staff: create (temporary password shown, no email in the demo), edit, change role, disable | Admin | ✓ |
| Roles: create, edit permissions, delete; Hotel Admin role cannot be deleted | Admin | ✓ |
| Roster, clock-in | Admin | ✓ |
| Super Admin: hotels list, hotel page, plans, switches, support inbox, system, team, activity, releases, watchdog, docs, content, platform reports | Super Admin | ✓ open, no errors |
| Log in as hotel and back (≈ 15 times) | Super Admin | ✓ |
| Room board: every filter, sort, tab; P19 filters at 375 / 768 / 1024 / 1280 | Admin | ✓ |
| UI shell: sidebar collapse remembered (`hms-sidebar-collapsed`), Ctrl+B, ⌘K, "/" (not while typing), theme switch, offline banner, support banner | Admin | ✓ |
| Print preview (invoice, registration card) | – | ✓ code unchanged vs `main` (not compared visually) |
| Edge: validation messages, double submit, pagination (4,000+ bills), empty search results | – | ✓ |
| Edge: back / forward, refresh mid-flow | – | ✓ routes keep their URL; not every form was refreshed mid-flow |

**P19 check (before → after, 375px):** the first room now starts at 637px (before: 1105px, below the screen). The Filters button is 44px; the count, the "Dirty" chip and Clear all work; it stays closed after a page change in the same session. At 1024px the desktop layout is unchanged (no button, all chips visible). Pictures: `after-roomboard-375.jpg`, `after-roomboard-375-filtered.jpg`, `after-bookings-375.jpg`, `after-roomboard-768.jpg`.

**Sizes:**
- **Sideways scroll:** none found at 375 / 768 / 1280 / 1920, apart from M4 (Super Admin team at 768px). At 1024px, see S1 and s2.
- **Contrast:** no text under 4.5:1 in light or dark on any screen, except M3.
- **Translations:** no raw translation keys and no console errors anywhere.
- **Speed:** no screen took over 2 s.

## 4. Problems, most important first

**Critical (data, access, money): none found.** No data leaks between roles or between Super Admin and hotels, money totals matched, and every money rule held.

### Serious

**S1 – Bill page: the Money card is cut off at 1024px (tablet landscape / small laptop)**
- **Where:** bill page `/billing/:id`, any role, 1024px (≈ 1024–1100px), light and dark.
- **Problem:** the right column (Money, Company) goes past the screen edge and the whole page scrolls sideways (186px).
- **Steps:** open any bill at 1024px wide.
- **Cause:** `lg:grid-cols-[1fr_22rem]` in `pages/billing/InvoicePage.tsx` lets the bill table's minimum width push the grid. Fix: `minmax(0,1fr)`.
- **Picture:** `bill-1024-cutoff.jpg`.

### Medium

**M1 – No "no access" page.** A role that opens a blocked URL is silently sent to its start page. `RequirePermission` and `RequireSuperAdmin` do `<Navigate to="/">`. All roles, all sizes. There is no data leak, but the person does not learn why.

**M2 – Front desk mode rows are 36px tall on touch screens** (19 targets at 375px). Front desk mode is made for touch, so the rows should be 44px+.

**M3 – Swipe actions are unreadable in dark mode.** On front desk mode (and other swipe lists), the "Check out" / "Check in" action behind a row is white text on the near-white dark-mode brand colour (1.67:1). It is seen when swiping on a phone in dark mode. Component: `components/ui/SwipeRow.tsx` (`bg-primary` + white text).

**M4 – Super Admin → Team at 768px:** the page scrolls sideways (198px). Light and dark.

**M5 – Tap targets under 44px on touch (375px):**
- header **Notifications** and **Theme** buttons are 42px on every page (`scale-95` in `AlertBell` / `ThemeSwitch`)
- breadcrumb links are 20px (every detail page)
- booking calendar day headers are 32px; room calendar booking bars are 16–24px
- guest links in the payments list are 17px; dashboard "needs attention" links are 17px
- kiosk "Leave kiosk" is 20px
- Super Admin team permission checkboxes are 16px; developer-docs list links are 15px

**M6 – `THIRD_PARTY_NOTICES` is not complete.**
- It says "no pages or code structure were copied as-is", but `components/shadcn/*` are copies of the template's files (sidebar, button, dropdown, dialog, sheet, command…), and `collapsible.tsx` follows the template's API.
- Lucide (ISC) is not listed. The Inter font (OFL), Radix UI and cmdk (MIT) are not listed either; that is optional for npm packages, but you asked for it.

### Small

- **s1** Housekeeping (and any role without `dashboard.view`): the start page calls `/dashboard` → **403** on every load (console error).
- **s2** Staff edit page `/staff/:id` at 1024px dark: 6px sideways scroll (outside `main`, source not found yet).
- **s3** 15 translation keys from the old UI are not used any more:
  - `topbar.closeMenu`, `topbar.darkMode`, `topbar.lightMode`, `topbar.mainBranch`, `topbar.superAdmin`
  - `dashboard.intro`, `dashboard.welcome`, `app.name`
  - `admin.newHotel.adminSection` / `hotelSection` / `planSection`
  - `reservations.policyLine_never`, `security.app.step2`, `support.reason_label`, `team.activity.page`

  No keys are missing; every `t()` key exists.
- **s4** Two labels are not translated: `aria-label="Breadcrumb"` (`PageHeader`) and `aria-label="Loading"` (`ProgressBar`).
- **s5** Role API errors use Laravel's default English ("The max discount percent field is required.", "The selected permissions.2 is invalid."). Only reachable through the API; the form always sends valid data.
- **s6** A second open cleaning task can be made for a room that already has one. Room 106 had two pending tasks. Business rule – your call.
- **s7** "Design system" shows in every role's sidebar in development builds (not in the live build).
- **s8** "What's new" is empty: there is no release note for the new look yet.
- **s9** The test announcement "hi the hi the…" is still live on the demo (already on your to-do list).

**Pictures:**
- `bill-1024-cutoff.jpg` (S1)
- P19: `after-roomboard-375.jpg`, `after-roomboard-375-filtered.jpg`, `after-bookings-375.jpg`, `after-roomboard-768.jpg`
- before P19: `before-roomboard-375.jpg`, `before-bookings-375.jpg`, `before-roomboard-768.jpg`
- #105: `after-1-2-phone-375.jpg`, `after-3-5-9-walkin-1024.jpg`, `after-7-settings-1024.jpg`

## 5. Documentation and other files

| File | State | What to change |
|---|---|---|
| `README.md` | OK | (Optional) say the `mobile/` folder does not exist yet; link `docs/local-setup.md` for the demo hotel. |
| `CLAUDE.md` | OK | Matches the app (shell, Lucide, tokens, touch, hosting). Add one line on the collapsible filters (below 1024px). |
| `MIGRATION_PLAN.md` | Outdated | Status table: #88–#107 are **merged** (it says "open"). Tick the P19 browser check (passed, §3) and note the P17 browser check result (this report). Add the remaining items: S1, M1–M6, guide pictures, staging test. |
| `QUESTIONS.md` | Missing answers | P19 questions 1–6 are waiting for your answers. |
| `docs/README.md` | OK | – |
| `docs/architecture.md` | **Wrong** | "Design rules" still say *Filament-style, zinc greys, teal primary, Heroicons (solid when active), 13px text, `bg-accent`, `ring-ring`*. Replace with the shadcn-admin rules from `CLAUDE.md` (slate OKLCH, Lucide, 14px, token map, 768 / 1024 breakpoints). |
| `docs/frontend.md` | Outdated | Line 34: "Heroicons are still used by screens not restyled yet" → only the 3 print pages' toolbars use Heroicons. Folder map: add `collapsible` to `components/shadcn/`. Otherwise current (sidebar, header search on the left, ⌘K, theme in the header, branch switcher, P19 filters). |
| `docs/local-setup.md`, `docs/hosting.md`, `docs/recipes.md` | OK | The artisan commands exist (`demo:reset --rooms`, `hotelms:backup`, `hotelms:reset-two-factor`…); paths exist. `recipes.md` has an example link `images/name.svg` (a placeholder, fine). |
| `docs/backend.md`, `docs/api.md`, `docs/data-model.md`, `docs/features.md` | OK | No restyle content; the paths named exist. |
| `components/shadcn/README.md` | Outdated | Add `collapsible.tsx` (own copy, no Radix package). |
| `THIRD_PARTY_NOTICES` | Wrong / missing | See M6: say the shadcn components were copied and adapted; add Lucide (ISC, with the Feather MIT part). Optional: Inter (OFL), Radix, cmdk (MIT). |
| LICENSE (HotelMS's own) | Missing | There is no licence file. Your call (closed source: "All rights reserved"). |
| Changelog / What's new | Missing | No release note exists. Write one for the new look before launch (Super Admin → Releases). |
| `backend/.env.example`, `frontend/.env.example` | OK | Only placeholders (`ChangeMe-123` is the documented first-start password, changed at first sign-in). |
| `docker-compose.yml`, Dockerfiles, `deploy/package.sh` | OK | Dev-only passwords (`secret`); the script passes a syntax check. |
| `.github/workflows/ci.yml` | OK | Lint, typecheck, tests, build, first-download size, `npm audit`, `composer audit`. |
| `frontend/components.json` | OK | The aliases point to existing folders; `iconLibrary: lucide`. |
| Project doc `handover.md` | Outdated | It still says #104 is open and the browser-check problems are not fixed. Update: #104–#107 merged, P19 done, this QA report, next steps. |
| In-app Help → How to use (`en.guide.json`, `guides.ts`) | OK | The texts match the new screens: avatar top right, sun button, branch at the top of the menu, search at the top, P19 steps in "Lists" and "Room board". Only English exists, so "every language" = English. |
| In-app developer docs (Super Admin → Developer docs) | Same as `docs/` | Same changes as the rows above. |
| Secrets / real data in files | OK | No real passwords, keys, guest data or internal URLs found. Telegram / mail secrets live only in the database. |

**Guide pictures to redo (all 15, old look):** roomBoard, walkIn, freeRooms, checkIn, moveExtend, checkOut, changeBooking, cancelBooking, noShow, takePayment, paymentProof, extraCharge, cashierShift, cleaning, clockIn.

**Translation files:** one language (`en.json` 2,359 keys + `en.guide.json`). No missing keys and no raw keys on screen. 15 unused keys (s3).

## 6. Proposed fix plan (small PRs into `ui/shadcn-admin`)

1. **Layout overflow** – S1 (bill page `minmax(0,1fr)`), M4 (Super Admin team at 768px), s2. Check at 768 / 1024 / 1150px.
2. **No-access page** – M1: a short "You can't open this page" screen with a "Go to start" button, instead of a silent redirect. Your OK needed (it changes behaviour).
3. **Touch and dark mode** – M2, M3, M5: 44px rows in front desk mode, readable swipe actions in dark mode, header icon buttons, breadcrumbs, calendar headers, small links.
4. **Small clean-up** – s1 (don't call `/dashboard` without permission), s3 (remove unused keys), s4 (`t()` labels), s5 (easy-English role errors).
5. **Docs** – `architecture.md`, `frontend.md`, `components/shadcn/README.md`, `MIGRATION_PLAN.md` status, `THIRD_PARTY_NOTICES`, `handover.md`, and the release-note text for you to publish.
6. **Guide pictures** – redo all 15 after 1–3 are merged.

**Questions for you (my recommendation first):**
1. Blocked URL: show a "no access" page (recommended), or keep the silent redirect?
2. s6: block a second open cleaning task for the same room (recommended), or allow it?
3. HotelMS licence file: add "All rights reserved" (recommended), or none?
4. Should I write the "What's new" text for the new look for you to publish? (Recommended: yes.)

---

## 7. Re-check after the fixes (8 Nov, evening) – part 1

Tested on `ui/shadcn-admin` @ `78ba589` (#109–#117 merged, migrated, demo reset). Super Admin "log in as hotel", reason *"QA re-check after fixes (owner request)"*.

### Saved once – every money and data action (owner, #116)
Each action was sent **twice at the same moment with the same key** (double-click / two tabs) and **again 5.5 s later with the same key** (slow network / retry after a timeout).

| Action | Role | Twice at once | Retry after 5.5 s | Result |
|---|---|---|---|---|
| New guest | Admin | 201 + 201 (1 replay, same guest) | replay, same | ✓ saved once |
| Walk-in (telebirr 7475) | Admin | 201 + 201 (same stay) | replay, same | ✓ |
| Payment on a booking (telebirr 100) | Admin | 201 + 201 (1 replay) | replay, same | ✓ one payment of 100 |
| Real second payment (new key, 50 + 50) | Admin | – | – | ✓ both saved |
| Same key, other amount | Admin | 422 `idempotency_mismatch` | – | ✓ refused |
| Quick charge (Breakfast) | Admin | 201 + 201 (1 replay) | replay, same | ✓ |
| Bill: extra charge | Admin | 201 + 201 | replay, same | ✓ |
| Bill: discount 5% | Admin | 201 + 201 | replay, same | ✓ |
| Refund 50 (telebirr) | Admin | 201 + 201 | replay, same | ✓ |
| Extend +1 night | Admin | 200 + 200 | replay, same | ✓ |
| Move room 110 → 106 | Admin | 200 + 200 | replay, same | ✓ |
| Check-out | Admin | 200 + 200 | replay, same | ✓ |
| New booking | Admin | 201 + 201 (guest has 1 more booking, not 2) | replay, same | ✓ |
| Cancel booking | Admin | 200 + 200 | replay, same | ✓ |
| No-show | Admin | 200 + 200 | replay, same | ✓ |
| Check-in | Admin | 200 + 200 | replay, same | ✓ |
| Approve price suggestion | Admin | 200 + 200 (1 replay) | replay | ✓ |
| Reject price suggestion | Admin | 200 + 200 (1 replay) | replay | ✓ |
| Cashier: close, open, close | Reception | 200/201 + replay each | – | ✓ one new shift (#148) |

**In the app, with every change delayed 5 s:** a real double-click on "Add guest" sent **one** request (one key) and made **one** guest.
Business rules still answered first where they apply (cash needs an open shift in the same branch; paying more than owed asks first; extend into a booked night is refused).

**Scheduler / clean-up:** Docker runs it (`scheduler` service, `schedule:work`); Super Admin → System shows the scheduler green. The cPanel cron line is in `docs/hosting.md` (2a step 6) and in the go-live checklist.
**Rollback:** the migration's `down()` drops the table. Checked locally (migrate → rollback → migrate). CI now runs `migrate`, `migrate:rollback --step=1`, `migrate` before the tests on every PR.

### Part 2 to-do list (done – results in §8)
1. **Guide pictures (15)** – plan, screens, demo data and marks: `tools/pictures/guide-pictures.md`.
2. **QA re-run** on everything fixed, at 375 / 768 / 1024 / 1280 / 1920, light and dark:
   - bill page 1024 (S1), Super Admin team 768 (M4), staff page 1024 dark (s2);
   - "You can't open this page" for each role (M1);
   - tap targets at 375 (M2, M5): front desk mode rows, header icons, breadcrumbs, calendar headers, small links, team checkboxes;
   - swipe action in dark mode (M3); housekeeping start page with no 403 (s1); no raw keys after the removed texts (s3);
   - "one open task per room and kind" message and link (rule);
   - demo approvals approve / reject in the screen;
   - calendar bar opens the booking on the first tap (#117);
   - full crawl again (all routes × sizes × modes) for regressions.
3. Update this report (part 2) with the results.


## 8. Re-check after the fixes – part 2 (8 Nov, night)

- **Branch / commit:** `ui/shadcn-admin` @ `edd5eab` (everything up to #118), in the owner's browser at `localhost:5173`. Fixes found tonight are in **#119** (not merged, so not in the browser yet; each was tried in the page first).
- **Hotel:** only Sheger Demo Hotel (id 15, then **16** after one demo reset – see below). Roles used through Super Admin "log in as hotel" with a reason. No passwords typed.

### How it was checked
The same script as part 1 opened every screen and recorded sideways scroll, text contrast, raw translation keys, tap targets under 44px (touch, 375px), console errors, failed or slow API calls, and where the browser was sent.
- **Hotel screens:** 53 addresses (every page, detail page, settings section and the two print pages), as Hotel Admin, at **375 / 768 / 1024 / 1280 / 1920px, light and dark** (10 runs).
- **Super Admin:** 22 addresses (every Super Admin page, a hotel page, the new-hotel form – opened, not sent – docs, profile, help, what's new, and two hotel addresses), 10 runs.
- **Roles:** every role (Manager, Reception, Housekeeping, Accountant, Restaurant / Bar) opened all 53 addresses + `/admin` at 1280px.
- Scenario checks by hand (below).

### Fixed items – result

| Item | Result |
|---|---|
| S1 bill page at 1024px | ✓ no sideways scroll (light and dark) |
| **New:** bill page at **768px** scrolled sideways 66px | ✗ → fixed in #119 (one-column grid, all 7 two-column screens) |
| M4 Super Admin → Team at 768px | ✓ |
| s2 staff page at 1024px dark | ✓ |
| M1 "You can't open this page" | ✓ for every role and every blocked address (also Super Admin on hotel addresses). No silent redirects; Restaurant's start page still goes to Charge a room, as designed |
| M2 front desk mode rows, M5 header icons, breadcrumbs, calendar day headers, kiosk link, Team checkboxes | ✓ 44px on touch |
| **New:** small links still under 44px on touch: Smart hint "Open", cashier booking links, walk-in "Make a reservation", bill → booking, booking → guest, "Add proof", Super Admin activity hotel links, developer-docs file links | ✗ → fixed in #119 (`tap-link`) |
| Booking and room calendar bars (16–28px) | kept, as you decided; a tap opens the booking (see #117 below) |
| M3 swipe action in dark mode | ✓ dark text on the brand colour (about 6:1) |
| s1 housekeeping start page 403 | ✓ no error |
| **New:** Accountant's Cashier page asked for "my shift" → 403 on every load | ✗ → fixed in #119 (test added) |
| **New:** Branch Manager → Settings → Currencies: list gave 403 | ✗ → fixed in #119 (server, test added; QUESTIONS.md "QA part 2" 1) |
| s3 / s4 raw translation keys | ✓ none on any screen, label or tooltip |
| Text contrast | ✓ nothing under 4.5:1 in any run (light and dark) |
| Rule "one open task per room and kind" | ✓ Manager adding a second cleaning task for room 110: clear message + **Show the open task** link, which jumps to that task. Still one open task |
| Demo approvals | ✓ approved one and rejected one in the screen; both show the manager's name in Approved / Rejected |
| #117 calendar bar on touch | ✓ a touch tap opens the booking, no hover card; a mouse still shows the hover card |
| Guide pictures | ✓ all 15 redone in the new look (#119) |
| Console errors, crashes, failed calls | ✓ none, except the ones fixed above. A one-branch person opening a booking of the other branch gets the friendly "Not found … branch you do not work in" page (correct) |

### Role × screen – re-check
Same as §2, now with the "You can't open this page" screen instead of a redirect. Sidebars show exactly the allowed screens.
Differences from §2: Restaurant / Bar still opens `/reports` by address ("Your role has no reports", no data) – QUESTIONS.md "QA part 2" 2.

### Notes
- **Demo reset:** the two demo approvals had already been decided in part 1, so I reset the demo hotel once (Super Admin, demo only). The demo is now hotel 16; my part-1 test data is gone.
- **Speed:** on the first load of the dashboard, `/dashboard` and `/smart/dashboard` sometimes took 2.5–5 s in the dev setup (Docker on your laptop, many requests at once). Alone they take about 0.3 s. Worth a look on the live server; not a code problem found.
- The test announcement "hi the hi the…" is still live on the demo (§4 s9, your to-do).
- "What's new" is still empty until you publish the draft (go-live checklist).

### Still open
- LICENSE placeholders – must be filled before the final PR to `main`.
- Restaurant / Bar → `/reports` by address (your call).
- Things I cannot test (you check in person): password change, kiosk PIN, two-step sign-in, screen-lock unlock, session timeout, sign out / in. See the final report.

## 9. Dashboards (P18) – check (9 Nov, morning)

- **Code:** `p18/modules` @ `d9068ba` (= #121–#123, merged) in the owner's browser at `localhost:5173`; fixes found today are in **#124** (each one also tried in the page first).
- **Hotel:** only Sheger Demo Hotel (id 16). Roles through Super Admin "log in as hotel" with a reason. No passwords typed, nothing saved (side sheets opened and closed only).

### How it was checked
`tools/pictures/dash-check.js` opened every dashboard screen and waited until it stopped changing, then recorded: sideways scroll, boxes sticking out, widget errors, charts drawn, "You can't open this page", money shown (ETB), tap targets under 44px (touch) and text under 4.5:1 contrast – in **light and dark**.
- **Screens:** hotel dashboard (Overview, Revenue, Operations), Cashier shift, Housekeeping, Reports (Overview), Super Admin command center.
- **Roles × sizes:** Hotel Admin, Branch Manager, Receptionist, Housekeeping, Accountant, Restaurant / Bar and Super Admin, each at **375 / 768 / 1024 / 1280 / 1920px**.

### Result

| Check | Result |
|---|---|
| Charts draw (Recharts) | ✓ every role and size; Operations tab uses plain bars by design |
| Widget errors, crashes, console errors | ✓ none |
| Text contrast | ✓ nothing under 4.5:1, light and dark |
| Money hidden from staff without it | ✓ Receptionist and Housekeeping: no ETB on the dashboard, no Revenue tab, no Unpaid list (the server also leaves the money out – tests in #121). Receptionist's cashier page shows only her own shift |
| Blocked screens | ✓ Housekeeping → Cashier, Accountant → Housekeeping, Restaurant / Bar → Cashier, Housekeeping and Reports: "You can't open this page" |
| Restaurant / Bar start page | ✓ Charge a room, as designed |
| Command center | ✓ demo hotel left out (1 real hotel); income shows its empty state until a hotel pays |
| Side sheets | ✓ KPI sheet (day by day) opens with **View all →** the full report |
| **New:** range / period buttons 42px on touch | ✗ → fixed in #124 (44px) |
| **New:** Refresh on its own line under Filters (phones) | ✗ → fixed in #124 (next to Filters) |
| **New:** whole page scrolled sideways at 768px (desk table) | ✗ → fixed in #124 |
| **New:** 5 + 1 KPI cards at 1280px, 7 + 2 on the command center | ✗ → fixed in #124 (balanced rows) |
| Accountant's Reports | ✓ open on the Daily manager report (owner's answer, 8 Nov); the Overview is one tap away |
| **New:** daily report boxes spilled out at 1024px (older screen) | ✗ → fixed in #124 |

### Speed (owner's PC, demo Hotel Admin, warm)

| | Before (#119) | After (#120 + #124) |
|---|---|---|
| `/dashboard` alone | 282 ms | 188 ms |
| `/smart/dashboard` alone | 266 ms | 68 ms |
| All 12 dashboard requests at once | 1.05–1.15 s | 0.56–0.69 s |
| Full page: dashboard data window | 1.9 s | 1.0 s |

- The 2–5 s first loads came from the **Docker dev server answering one request at a time** (Laravel ignored `PHP_CLI_SERVER_WORKERS` without `--no-reload`, fixed in #124) plus Vite's dev start-up. cPanel / VPS answer requests in parallel.
- The browser sends at most 6 requests at once to one address over HTTP/1.1 (the dev setup), so the page needs about 1.0 s locally; HTTP/2 on the live server removes that limit.
- Big-hotel test in CI (160 rooms, 15,000 bookings): `/dashboard` 42 → 25 database questions.
- **Chart code size:** Recharts and our chart parts are one shared file of **118.3 KB gzip** (`KpiCard-*.js` in the build), loaded the first time a dashboard screen opens (hotel dashboard, cashier, housekeeping, reports or command center) and then reused. Each screen's own file is under 30 KB. First download **257.4 KB gzip** (253.6 before P18). CI prints both on every PR.

### Still open
- "Recent payments" on the Revenue tab ignores the branch filter (server change – QUESTIONS.md "P18" 1).
- The screenshots tool in the browser pane draws slowly at big sizes; numbers above come from the page itself, not from pictures.
