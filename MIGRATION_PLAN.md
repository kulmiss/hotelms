# MIGRATION_PLAN – shadcn-admin look and feel

Goal (owner, 8 Nov 2026): when someone opens HotelMS it should **look and feel like shadcn-admin**
(layout, sidebar, header, Cmd+K, spacing, type, colours, dark mode, animations, interactions).
Code structure stays ours. Approved approach: the **smaller option**, pushed as close to the template as possible.

- Template: https://github.com/satnaing/shadcn-admin, **pinned to `e16c87f`** (MIT; notice kept in `THIRD_PARTY_NOTICES`).
- Copy **style and patterns only**: no demo pages (tasks, apps, chats, Clerk), no TanStack Router, no zod/react-hook-form.
- Comparison the owner approved: Design canvas "HotelMS dashboard – shadcn-admin look (comparison)".

## Owner decisions (8 Nov)
| # | Decision |
|---|---|
| 1 | Smaller option: tokens, dark mode, typography, layout shell (sidebar, header, Cmd+K) and all 21 shared building blocks; then fix screens that still look different, in later partitions |
| 2 | `CLAUDE.md` design rules change to the shadcn-admin style |
| 3 | Lucide icons instead of Heroicons (done once in the building blocks, then screen by screen) |
| 4 | Template sizes on desktop; **44 px tap targets on touch screens only** (`pointer: coarse`) |
| 5 | Template's neutral (slate) look; **Super Admin brand colour = primary / accent** |
| 6 | Keep React Router; the navigation must *feel* the same (active states, collapsible sidebar, transitions) |
| 7 | Work on `ui/shadcn-admin`, one PR per partition, never push to `main` |
| 8 | Stop after each partition and wait for the owner's OK |
| 9 | Priority screens: room board, walk-in, bookings, bills, dashboard |
| 10 | Deploy from `main` only |
| 11 | Staff-guide pictures: redo at the end |
| 12 | **Printed invoices and registration cards stay exactly as they are** |
| – | Every text stays in `t()` |

## 1. What the template is (study of `e16c87f`)

**Theme tokens** (`src/styles/theme.css`, shadcn "new-york", base colour **slate**, OKLCH):
`--background --foreground --card --popover --primary --secondary --muted --muted-foreground --accent --destructive --border --input --ring --chart-1..5 --sidebar-*`, `--radius: 0.625rem` (sm/md/lg/xl = r−4/r−2/r/r+4).
Light primary is near-black slate; dark primary is near-white. We swap primary for the brand colour.

**Fonts:** Inter (also Manrope / system as user choices). Body `text-sm` (14 px), page title `text-2xl font-bold tracking-tight`, card title `font-semibold`, stat `text-2xl font-bold`, hints `text-xs text-muted-foreground`.

**Layout:**
- `SidebarProvider` → `AppSidebar` (variant **inset**, collapsible **icon**, `SidebarRail`), width 16rem / 3rem icons / 18rem mobile sheet, 200 ms linear width transition, remembers state in a cookie.
- Sidebar: `TeamSwitcher` (logo square + name + plan, dropdown) · `NavGroup`s (label `text-xs`, items `h-8 text-sm`, active = `bg-sidebar-accent font-medium`, collapsible sub-items with chevron, badges, tooltips when collapsed, dropdown for sub-items when collapsed) · `NavUser` footer (avatar, name, email, dropdown: account, billing, notifications, sign out).
- `SidebarInset`: the content panel with `m-2 rounded-xl shadow-sm`.
- `Header` (h-16, sticky option, blur + shadow after scrolling 10 px): `SidebarTrigger` (outline, size-7) · vertical separator · (top nav) · `Search` (outline button `h-8 w-64`, ⌘K badge) · `ThemeSwitch` (ghost round, sun/moon, dropdown Light/Dark/System) · `ConfigDrawer` · `ProfileDropdown` (round avatar).
- `Main`: `px-4 py-6`, max-width 7xl on wide screens.
- `CommandMenu` (cmdk in a dialog): groups from the sidebar data + theme commands, ⌘K / Ctrl K.
- `NavigationProgress` (top loading bar), `sonner` toasts, `SkipToMain` link.

**Building blocks used:** button, card, badge, alert, input, textarea, label, select (Radix), checkbox, switch, radio-group, tabs, table + data-table (toolbar, faceted filter, column toggle, pagination), dialog, alert-dialog, sheet, dropdown-menu, popover, tooltip, collapsible, separator, scroll-area, avatar, skeleton, command, calendar, sonner.

## 2. What HotelMS has today (audit, `main` at 744d67c)

- Vite + React 19 + TypeScript, **Tailwind v4 already**, React Router 7, TanStack Query, react-i18next. No UI library; own building blocks.
- Icons: Heroicons in **67 files** (71 different icons; outline + solid for active nav).
- Styles: `src/index.css` – our tokens (`bg-bg`, `bg-surface`, `bg-sunken`, `text-text`, `text-muted`, `bg-accent`, `text-accent-text`, `ring-ring`, status colours `ok/warn/info/danger/grey`, room colours), `.panel`, `.doc`, keyframes, touch size `--touch` (36 px / 44 px on touch).
- Brand: `lib/brand.tsx` writes the Super Admin colour into `--accent*` at run time (light + dark).
- Theme: `lib/theme.tsx` light / dark / system with `.dark` on `<html>` (same idea as the template).
- Screens: **62 routes, 71 page files (~14,800 lines)**; layout 14 files; 21 building blocks; 34 test files.
- Building-block use: Button 67 files, Card 56, Alert 48, Field 45, PageHeader 43, Badge 41, Select 29, Tabs 15, DataTable 8 (+ ResourceList 3).
- Markup outside the building blocks (to restyle screen by screen): 51 raw `<input>` (mostly checkboxes / radios), 8 raw `<select>`, 26 raw `<table>`, 15 files with `.panel`, 1 dialog.

## 3. Inventory: ours → template equivalent

### Layout (`components/layout`)
| Ours | Template equivalent | Note |
|---|---|---|
| `AppShell` | `AuthenticatedLayout` (`SidebarProvider` + `AppSidebar` + `SidebarInset`) | keep our banners, screen lock, offline bar |
| `Sidebar` | `AppSidebar` + `NavGroup` + `TeamSwitcher` + `NavUser` | data from `app/navigation.ts` (permissions, modules, groups unchanged); branch switcher in the team-switcher slot; brand logo/name from `lib/brand.tsx` |
| `Topbar` | `Header` | trigger, separator, search, bell, theme, profile |
| `GlobalSearch` (Ctrl K) | `Search` button + `CommandMenu` (cmdk) | same search API, settings search and permissions; adds nav + theme commands |
| `UserMenu` | `NavUser` + `ProfileDropdown` | same items (profile, security, front desk mode, help, sign out…) |
| `AlertBell` | (none) → ghost round icon button + dropdown in header | |
| `ProgressBar` | `NavigationProgress` | look only |
| `HotelBanners`, `SessionBanner`, `ConnectionBanner`, `Maintenance` | (none) → shadcn `Alert` style, above the header | demo countdown, support banner, offline |
| `ScreenLock`, `RequireAuth`, `RouteError` | (none) → card / auth-page style | logic unchanged |

### Building blocks (`components/ui`) – all 21
| Ours | Template equivalent |
|---|---|
| Button | `button` (default, outline, secondary, ghost, destructive, link; sizes sm/default/lg/icon). Our `size="lg"` 52 px reception buttons stay for touch |
| Card | `card` (Card, Header, Title, Description, Content, Footer) |
| Badge, StatusBadge | `badge` (default/secondary/outline/destructive) + our status colours as soft badges |
| Alert | `alert` (default, destructive) + our info/warn/ok tones |
| Field | `label` + `input` / `textarea` + form message style |
| Select | `select` look on the native `<select>` (keeps phone pickers and tests); Radix only if a screen needs it |
| Tabs | `tabs` (muted pill list) |
| DataTable, ResourceList | `table` + data-table toolbar, column toggle (dropdown), pagination; phone cards stay |
| DateRangeFilter | popover + preset list (calendar look) |
| PageHeader | page title row (`text-2xl font-bold tracking-tight` + description + actions) |
| StatTile, Delta | dashboard stat `Card` |
| Toaster | `sonner` look (our own toaster restyled; no new library unless needed) |
| SmartHints | `alert` with icon + "Why?" link |
| LoadFailed | `alert` (destructive) + outline "Try again" |
| SwipeRow, PhotoPickers, QrCode, TempPassword | restyled with the tokens (no template equivalent) |

### Screens → template pattern
| Screens | Pattern |
|---|---|
| Dashboard, Super Admin dashboard | template **Dashboard** (stat cards, chart card, list card) |
| Room board, front desk mode, charge a room | cards grid + tabs + filters (data-table toolbar look); front desk mode stays full screen |
| Walk-in, new / change booking, guest form, staff form, role form, new hotel | **form** patterns (label above input, muted descriptions, card sections) |
| Bookings list, guests, bills, payments, cashier shifts, activity log, staff, hotels, support inbox | **Users / Tasks data-table** pattern |
| Booking page, bill page, guest page, hotel page | detail page: cards + tabs (Settings-like) |
| Calendars (bookings, room calendar) | cards + table look (no template equivalent) |
| Settings (all sections), profile, security, platform settings | template **Settings** layout (left sub-nav, `h3` + muted description, separator, forms) |
| Login, password reset, change password, two-step, screen lock | template **Auth** pages (centred card) |
| 404, error page, maintenance | template **Errors** pages |
| Reports, insights, approvals, help, what's new | cards + tabs + tables |
| **Invoice print, registration card print** | **not touched** (owner #12) |

## 4. Risks and what cannot match 100%

1. **I cannot run npm in my workspace** (package downloads are blocked). **Owner decision (8 Nov): real packages** (lucide-react, Radix parts, cmdk…), installed **once by the owner** with the pinned command from the P1 report; he commits `package.json` + `package-lock.json` to the branch. Typecheck, lint, tests and build run on **GitHub CI** after each push. For the visual check in the browser, the owner switches his local copy to the branch (commands in each report), then I check light/dark, phone and tablet in the browser pane.
2. **Deploy safety:** PRs go into `ui/shadcn-admin` (not `main`), so `main` is never half-restyled. A last PR `ui/shadcn-admin → main` when everything is done. *(Needs the owner's OK – question 1.)*
3. **Brand colour as primary:** some brand colours are light (e.g. yellow). `lib/brand.tsx` already picks dark or white text on the colour; it will feed `--primary` / `--primary-foreground` / `--ring` too.
4. **Touch sizes:** template buttons are 32–36 px; on touch screens they become 44 px (decision 4). Slightly taller rows on tablets than the template.
5. **Native `<select>`** keeps the phone's own picker (better on tablets and for tests); it is styled like the shadcn select trigger, but the open list is the device's, not a Radix popover.
6. **Tests** find things by role and text. Restyling keeps roles and texts, but Radix menus (user menu, column toggle) behave differently in jsdom; some tests need small updates (same assertions, new way to open the menu).
7. **Bundle size (speed):** first download stays **≤ 60 KB** (owner). Lucide icons are imported **one by one** (`import { House } from 'lucide-react'`, never the whole set or dynamic names); **cmdk and other heavy parts are lazy-loaded** (the command menu loads on first ⌘K / click); Radix parts load with the screen that uses them where possible. Screens stay lazy-loaded. The first-download size is measured from the build output in every report.
8. **Fonts:** the template loads Inter from Google Fonts; we keep the **self-hosted** Inter (`@fontsource-variable/inter`) so it works offline and on cPanel without Google.
9. **No top nav links:** the template's Overview / Customers / Products links have no equivalent in HotelMS; so, like the template's pages without top links, the search sits on the left next to the menu button (owner, 8 Nov).
10. **Print pages** use their own black-on-white styles; token changes must not reach them (checked in every partition).
11. **Staff-guide pictures** (15) will be out of date until the last partition.

## 5. Partitions

Each partition: list files → change → compare old/new for lost buttons, handlers, fields, messages, loading / empty / error states and permission checks → CI green (typecheck, lint, tests, build) → browser check (light, dark, 375 px, 768 px, desktop) → commit(s) → tick here → short report → **stop for OK**.

**Every partition also (owner, 8 Nov):**
- [ ] **Merge `main`** into `ui/shadcn-admin` (and the partition branch) first, so fixes on `main` are never lost and the final merge stays small
- [ ] **Room / status colour contrast** checked in light and dark (room cards, status badges, chips – readable text, WCAG AA 4.5:1 for text)
- [ ] **Offline check:** the app shell, fonts and icons work with the network off (offline banner, cached screens); nothing loaded from outside sites
- [ ] **Every new text goes through `t()`** (`en.json`); no hard-coded words
- [ ] **Before / after screenshots** of the changed screens in the report
- [ ] First-download size noted in the report: **running total of what the restyle adds** (budget 60 KB gzip, owner 8 Nov) and the full first download

**Report template (owner, 8 Nov):** every partition report ends with: *merge PR #… into `ui/shadcn-admin`, then* `git checkout ui/shadcn-admin && git pull && docker compose up -d --build`. Never "checkout main" for restyle PRs (only the final PR goes to `main`).

**Size log (gzip, from the CI run summary):**
| After | First download | Added by the restyle | Notes |
|---|---:|---:|---|
| P1 (#86) | ≈ 218.5 KB | ≈ 0 | estimate from the raw build sizes (CI did not measure yet) |
| P2 (#87) | 251.6 KB | ≈ 33 KB | Radix menus / tooltip / dialog, Lucide nav icons, tw-animate CSS; cmdk is lazy (CommandMenu chunk, not counted) |
| P2 fixes (#88) | 252.2 KB | ≈ 33.7 KB | lock screen / security items |
| P3 (#89) | 252.9 KB | ≈ 34.4 KB | building blocks, checkbox CSS |
| P17 (#103, all partitions) | 252.5 KB | **≈ 34 KB** | screens are lazy, so P4–P16 add nothing to the first download |

### P1 – Foundation (tokens, fonts, theme, notices)
- [x] Packages: owner chose real packages; he runs the pinned install once (command in the P1 report) before P2. P1 itself needs no new package
- [x] `components.json` (template's, aliases adapted to our folders)
- [x] shadcn tokens (slate, OKLCH, radius) in `index.css`, light + dark
- [x] Our old token names (`bg-surface`, `text-muted`, `bg-accent`…) point at the new values, so **every screen changes colour at once** and nothing breaks
- [x] Brand colour → primary: `lib/brand.tsx` already writes it into `--accent` / `--on-accent` (= shadcn primary / primary-foreground); the sidebar uses it in P2
- [x] Base styles from the template (border colour, focus ring `ring/50`, cursor pointer, thin scrollbars, 16 px inputs on phones)
- [x] `cn()` uses `clsx` + `tailwind-merge` → done in P2
- [x] `THIRD_PARTY_NOTICES` with the template's MIT licence
- [x] `CLAUDE.md` design rules → shadcn-admin style (Lucide, slate tokens, brand primary, touch rule)
- [x] Check (owner's machine, 8 Nov): dashboard, room board, walk-in, bookings, bills render in light / dark, phone / desktop; invoice print unchanged; no new errors

### P2 – Layout shell
- [x] Owner's package install committed (`package.json` + `package-lock.json`, 90b0068); `cn()` with `clsx` + `tailwind-merge`
- [x] shadcn primitives from the template in `components/shadcn/` (sidebar, button, dropdown menu, popover, tooltip, dialog, sheet, command, separator); class names translated to our tokens (README there)
- [x] Sidebar (inset, collapsible to icons, rail, tooltips when collapsed, phone drawer below 768px, state remembered with the old key), groups / items from `app/navigation.ts` (Lucide icons). *No Settings sub-menu: Settings keeps its own page menu.*
- [x] Branch switcher in the team-switcher slot; brand logo + name from Super Admin branding, branch as the second line
- [x] `NavUser` footer and `ProfileDropdown` with all current user-menu items (profile, password, front desk mode, what's new, help, sign out)
- [x] Header: trigger, separator, search button (Ctrl/⌘K), alert bell (Radix popover), theme switch (Light / Dark / Same as device – moved out of the account menu, like the template), profile; blur + shadow when scrolled
- [x] Command menu (cmdk, **lazy**): server search + settings search + pages + theme, same permissions; Enter opens the first result
- [x] Banners (demo countdown, support, offline, new version, read-only, HotelMS messages) as strips above the header (Lucide icons); progress bar 2px grey
- [x] Super Admin uses the same shell with its own nav
- [x] Skip-to-main link; keyboard: Ctrl/⌘K, `/`, Ctrl/⌘B, Esc; touch: 44px targets (collapsed sidebar 3.75rem wide on touch screens)
- [x] CI prints the first-download size (`frontend/scripts/first-download.mjs`, run summary)
- [x] Tablet widths (768–1023px) start with the sidebar collapsed to icons unless the person chose otherwise on that device (owner, 8 Nov)
- [x] "/" typed in a box never opens the search (test)
- [ ] Browser check on the owner's machine (light / dark, 375 / 768 / 1024 / desktop, keyboard, room board at 768 and 1024)

### P3 – The 21 building blocks + Lucide
- [x] Button, Card, Badge, StatusBadge, Alert, Field, Select, Tabs, PageHeader, StatTile, Delta (same props; template classes; `components/ui/formStyles.ts` holds the input / select / label classes for raw fields on screens)
- [x] DataTable + ResourceList (template table, toolbar, Radix "Columns" menu that stays open while ticking, pagination, phone cards)
- [x] DateRangeFilter (kept preset + dates, restyled – QUESTIONS P3-5), Toaster (sonner look), SmartHints, LoadFailed, SwipeRow, PhotoPickers, QrCode, TempPassword
- [x] Native checkboxes / radios look like the template's (CSS only, no form code changed)
- [x] Heroicons → Lucide inside the building blocks and the navigation
- [x] Screens that still look different: raw inputs / selects / tables and Heroicons inside pages → P4–P16

### P4 – Dashboard (hotel)
- [x] Template dashboard pattern (as on the approved canvas): stat cards (title + icon, big number, small line), "next 7 days" chart card, rooms card, money cards in the same card style, smart hint alert; occupancy shows "85%" on one line (was "85" with "%" below)

### P5 – Room board, front desk mode, charge a room
- [x] Room cards (template card shape: rounded-xl, border, small shadow; room colours kept), status / today filter chips as the template's outline filter buttons, toolbar search + selects in the template look, tabs, room panel, Arriving / Leaving lists, front desk mode tiles, Charge a room, quick charges; Lucide icons

### P6 – Walk-in
- [x] Form pattern: sections as cards, stay-type toggle (template outline buttons), room picker (template card shape, brand outline when chosen), guest match box, quick check-in, summary card, payment; checkboxes in the template look; Lucide

### P7 – Bookings
- [x] List (data-table, search with icon, status filter chips), calendar (bars with borders, today column), new / change form (stay-type toggle, room picker, guest search), booking page, stay panels (check-in, check-out, move, extend, pay), room calendar; Lucide. **Registration card page not touched** (it is the print page)

### P8 – Bills
- [x] Billing list (bills, payments; search with icon, filter chips), bill page (lines, delete buttons, void card), charge / discount / payment panels, payment proof; Lucide. **Invoice print untouched** (no diff in `InvoicePrintPage.tsx` / `components/print`)

### P9 – Guests

- [x] Guest list (search with icon), guest page (details, stays, blacklist), guest form, ID photo; Lucide
### P10 – Modules

- [x] Cashier, housekeeping (room cards, task list selects), shifts / roster (select boxes), attendance, kiosk keypad; Lucide
### P11 – Reports and approvals

- [x] Reports library (template side menu), every report (KPI cards, tables, heat map, pickup, targets), insights, automation reports, approvals; filter chips; Lucide
### P12 – Settings and setup

- [x] Settings layout (template settings side menu + search box), all sections, switches in the template look, import preview, automations, setup walkthrough; checkboxes incl. switch-role ones styled; Lucide
### P13 – Team, profile, security

- [x] Staff list and form (role choice cards), roles and role form, devices, activity log (search box), My profile, security cards (two-step choice cards); Lucide
### P14 – Sign-in pages

- [x] Login, forgot / reset password, change password, two-step, screen lock: template auth layout (brand row, 480px card, left-aligned title + description), Lucide
### P15 – Super Admin

- [x] Command center, hotels (filter buttons), hotel page + tabs, new hotel, plans, switches, support inbox + conversation, releases, content, watchdog (severity borders), docs, system, activity, team, platform settings, platform reports; Lucide
### P16 – The rest

- [x] Help (guides), what's new, 404 and screen-error pages (template Errors pattern: big number, short line, buttons), placeholder, design-system page; Lucide. Subscription invoice page not touched (print page)
### P17 – Cleanup and final check
- [x] Dead CSS removed after a codebase search (`menu-in` animation); old layout files removed in P2. **Heroicons kept**: the 3 print pages still use it (QUESTIONS P17-1); no package removed (QUESTIONS P17-2/3)
- [x] Second-pass review of the whole branch against `main`: every changed screen file compared for handlers, mutations, navigation, `t()` texts, roles and labels – nothing lost (the only drops are explained: bell / columns menus now open through Radix, `disabled:` CSS classes moved into `formStyles`); print pages: no diff
- [x] Leftover ring class fixed (new hotel plan cards); dev pre-bundling lists lucide-react; docs; QUESTIONS.md complete
- [x] All checks green on every PR (#88–#103): lint (0 errors; the 8 warnings are old), typecheck, 161 tests, build
- [x] Every route in light / dark at 375 / 768 / 1024 / 1280 / 1920 – full QA pass 8 Nov (`QA_REPORT.md`, #108); problems fixed in the QA fix PRs below
- [x] Unused packages removed (owner, 8 Nov): `sonner` and 9 unused Radix parts (#104); Heroicons kept for the print pages
- [ ] Redo the 15 staff-guide pictures (owner: after the browser check passes) (`tools/pictures/`) – needs the running app
- [ ] **Staging test** before the final PR: deploy `ui/shadcn-admin` to a staging copy (shared-hosting style build), click through as every role
- [ ] Final PR `ui/shadcn-admin → main` (after the staging test)

### P19 – Collapsible filters on phones and tablets (owner, 8 Nov; after #105)
- [x] Audit: every page with a filter or sort bar (list in QUESTIONS.md P19)
- [x] One shared block, `CollapsibleToolbar`, used by `DataTable`, `ResourceList` and the room board; closed below 1024px, desktop unchanged
- [x] Search stays; count badge; removable chips; Clear all; open/closed per page for the session only
- [x] Keyboard and screen readers: `aria-expanded`, `aria-controls`, closed part `inert`, Enter opens and goes to the first control, Escape closes and goes back, focus never lost after × / Clear all
- [x] Tests (collapsed below 1024px, desktop as before, count and chips, Clear all, session memory, keyboard, room board on a phone)
- [x] Browser check at 375 / 768 / 1024 / 1280, light and dark, before/after pictures (8 Nov: first room at 637px instead of 1105px on a phone)

| Partition | Status | PR |
|---|---|---|
| P0 Plan | approved 8 Nov | – |
| P1 Foundation | merged 8 Nov | #86 |
| P2 Layout shell | merged 8 Nov | #87 |
| P2 fixes | merged 8 Nov | #88 |
| P3 Building blocks | merged 8 Nov (with #103) | #89 |
| P4 Dashboard | merged 8 Nov (with #103) | #90 |
| P5 Room board, front desk mode, charge a room | merged 8 Nov (with #103) | #91 |
| P6 Walk-in | merged 8 Nov (with #103) | #92 |
| P7 Bookings | merged 8 Nov (with #103) | #93 |
| P8 Bills | merged 8 Nov (with #103) | #94 |
| P9 | merged 8 Nov (with #103) | #95 |
| P10 | merged 8 Nov (with #103) | #96 |
| P11 | merged 8 Nov (with #103) | #97 |
| P12 | merged 8 Nov (with #103) | #98 |
| P13 | merged 8 Nov (with #103) | #99 |
| P14 | merged 8 Nov (with #103) | #100 |
| P15 | merged 8 Nov (with #103) | #101 |
| P16 | merged 8 Nov (with #103) | #102 |
| P17 | merged 8 Nov | #103 |
| P17 package cleanup | merged 8 Nov | #104 |
| Browser-check fixes | merged 8 Nov | #105 |
| P19 Collapsible filters | merged 8 Nov | #106 → #107 |
| Full QA pass | report | #108 |
| QA fixes 1–5 | open | #109–#113 |
