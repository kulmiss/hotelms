# Frontend (React)

## Folder map (`frontend/src/`)

| Path | What is there |
|---|---|
| `main.tsx` | Starts the app (providers, router, service worker) |
| `app/router.tsx` | Every screen and its URL. Screens are `lazy()` loaded |
| `app/prefetch.ts` | The import of each screen; hovered/focused links prefetch it (fast first open) |
| `app/navigation.ts` | The menu: items, icons, permission, module, group (`mainNav`, `adminNav`) |
| `app/providers.tsx` | TanStack Query client: retries, global error handling (401 → sign-in), a toast after every change (`meta.toast`) |
| `app/serviceWorker.ts` | Offline support (read-only copy, see below) |
| `components/layout/` | App shell (shadcn-admin layout): `AppShell` (SidebarProvider + inset), `AppSidebar` (menu groups from `app/navigation.ts`, `BranchSwitcher` at the top, `NavUser` at the bottom), `Header` (menu button, `Search` → lazy `CommandMenu` with cmdk, `AlertBell`, `ThemeSwitch`, `ProfileDropdown`), `ScreenLock`, banners |
| `components/shadcn/` | shadcn primitives from the template (Radix UI + cmdk): sidebar, button, dropdown menu, popover, tooltip, dialog, sheet, command, separator, collapsible (own copy with the template's API). Class names translated to our tokens (see its README) |
| `components/ui/` | The UI kit (shadcn-admin look, same props as before): `Button`, `Card`, `Field`, `Select` (+ `SelectBox` for selects without a label), `formStyles.ts` (template input / select / label classes for raw fields), `Tabs`, `Alert`, `Badge`, `PageHeader`, `DataTable` (every list: sort, 25/50/100 rows, hide columns, Excel, phone cards; the header scrolls with the rows), `DateRangeFilter`, `LoadFailed` (a screen whose data did not load shows "not found" / "not allowed" / "could not load" + Try again, never an empty page; `DataTable` takes `error` + `onRetry` for the same on lists), `ResourceList`, `SmartHints`, `StatTile`… |
| `components/billing`, `payments`, `guests`, `print`, `support` | Pieces shared by several screens (add charge, payment proof, ID photo, print header, support chat) |
| `pages/<area>/` | Screens: `desk/` (room board, walk-in, front desk mode), `reservations/`, `guests/`, `billing/`, `modules/` (cashier, housekeeping, shifts, attendance), `reports/`, `settings/`, `setup/`, `team/`, `admin/` (Super Admin), `help/`, `security/`, `approvals/`, `subscription/` |
| `lib/` | Data hooks and helpers per area (below) |
| `i18n/` | `index.ts`; `locales/en.json` (all screen text), `locales/en.guide.json` (staff guides) |
| `index.css` | Tailwind v4 theme in the **shadcn-admin** look (slate OKLCH tokens, radius 0.625rem; our token names map to shadcn's – see the comment at the top), `.panel` (= shadcn card), touch sizes, focus ring, print rules. Template notice: `THIRD_PARTY_NOTICES`; plan: `MIGRATION_PLAN.md` |
| `test/` | Vitest + Testing Library; `mockApi.ts` (fake API + `makeMe`), `renderApp.tsx` |

## Layout and shortcuts

- **Sidebar** (template, `variant="inset"`, `collapsible="icon"`): 16rem wide, 3rem when collapsed (3.75rem on touch screens so the 44px buttons fit), a drawer below 768px. Collapsed or not is saved on the device (`hms-sidebar-collapsed`); with no saved choice, tablet widths (768–1023px) start collapsed. Collapsed items show their name as a tooltip.
- **Filters on phones and tablets** (below 1024px, P19): filter, sort and other toolbar controls fold behind one **Filters** button (`components/ui/CollapsibleToolbar.tsx`), closed at first, so the data shows first. The search box stays in view; the button shows how many filters are on; each one is a chip with × and there is **Clear all**. Open/closed is kept per page for the browser session only (`sessionStorage` `hms-filters-open-<id>`). From 1024px nothing changes. Tabs stay outside (they are navigation). Only the look changes: pages keep their own filter state, URLs and query parameters.
  - Lists: give `DataTable` `search`, `filters` (desktop layout, with `<ToolbarSearch />` where the search box goes), `activeFilters` (`{ key, label, onRemove }`; no `onRemove` = shown for information, not counted) and `onClearFilters`. Excel and Columns fold away with them. `ResourceList` takes `filters` + `activeFilters` the same way.
  - Other screens (room board, reports, attendance, Super Admin health) wrap their controls in `<CollapsibleToolbar>` themselves. Buttons that must stay in view (the dashboard's Refresh) go in its `actions` prop: next to the Filters button when folded, after the filters on desktop. Date filters: `rangeChip()` in `lib/dateRange.ts` makes the chip ("Stayed: This month"). `useCompactToolbar()` (`lib/compactToolbar.ts`) tells a screen it is below 1024px.
  - `components/shadcn/collapsible.tsx` is the template's Collapsible API (same names and `data-state`) written without `@radix-ui/react-collapsible` (removed in #104); the closed part is `inert`, the height animates in 200ms (none with reduced motion).
- **Phones**: tab rows wrap to a second line (CSS in `index.css`, `[data-slot=tabs-list]`); the header search box shrinks instead of covering the menu button.
- **Forms next to a summary card** (walk-in, new booking): the stay fields use a container query on their card (`@container`, `@sm:grid-cols-2 @2xl:grid-cols-3`), so dates always show in full whatever the screen and sidebar width.
- **Grey text**: `text-muted` is darker than the template's (≥ 4.5:1 on white, grey boxes and room colours); small text on room cards uses `text-grey-text`.
- **Shortcuts**: Ctrl/⌘+K or `/` opens the command menu (search, pages, theme); Ctrl/⌘+B opens/closes the sidebar; Tab from the top shows "Skip to main content".
- **Icons**: Lucide, imported one by one (`import { House } from 'lucide-react'`) so only the used icons are downloaded. Heroicons are left only in the on-screen toolbar of the three print pages (invoice, registration card, HotelMS invoice).
- **Layout rules** (QA 8 Nov): two-column screens use `grid-cols-[minmax(0,1fr)] lg:grid-cols-[minmax(0,1fr)_22rem]` (never `1fr`, and keep the one-column `grid-cols-[minmax(0,1fr)]` too, or a wide table pushes the page sideways on a tablet); a scrolling table card that has hidden (`sr-only`) labels is `relative`; long words such as email addresses in a flex label sit in a `min-w-0 [overflow-wrap:anywhere]` span; small links and text buttons get a 44px tap area on touch with the `tap-link` class (`index.css`; no layout change). Older code uses `pointer-coarse:-my-3 pointer-coarse:inline-block pointer-coarse:py-3`, which is enough only for 20px-tall text.
- **Speed**: cmdk and the command menu are loaded only when the menu is first opened (or the search button is hovered).

## Data loading

- `lib/api.ts`: `api()`, `post`, `put`, `del`, `upload`. Cookies + XSRF header (Sanctum). Errors become `ApiError`
  (`status`, `message`, `field(name)` for validation errors). Offline: changes are refused with a clear message.
  **Paying more than owed**: when the server answers `confirm_overpay`, `api()` asks "Is that right?" and sends again with `confirm_overpay: true`.
- One `lib/<area>.ts` per area with TanStack Query hooks: `desk.ts` (board, today, check-in/out, bills), `front.ts`
  (reservations, calendar, quotes, lookups), `modules.ts`, `reports.ts`, `settings.ts`, `admin.ts`, `platform.ts`, `security.ts`, `automation.ts`, `smart.ts`…
- **Query keys** start with the area (`['desk', …]`, `['reservations', …]`); after a change, each `lib/<area>.ts` has a small `useInvalidate`/`useDeskInvalidate` helper that refreshes that area.
- **Money actions** use `useMoneyAction` (`lib/desk.ts`): the same `Idempotency-Key` until the server says yes (no double payment on a bad network).
- **Live screens** poll (`refetchInterval: 30_000`); no WebSockets (hosting limit).

- **Send once** (owner, QA 8 Nov): every change is one submission with its own `Idempotency-Key` (a new UUID per `api()` call; money forms may pass their own, `useMoneyAction`). If the network drops or times out, or the server answers 409 `busy`, `api()` sends the same submission again with the **same key** (twice / three times, with short waits), so the server answers with the first result. A change exactly the same as one still on its way is sent only once (double-click on a slow network). Buttons stay disabled while sending (`isPending`). There is no offline queue: offline, changes are refused (read-only copy).

## Who sees what

- `useMe()` (`lib/auth.ts`): the signed-in person, hotel, branches, role, `permissions`, modules, switches, impersonation.
- `useCan()` (`lib/hotel.ts`): `can('x.y')` or `can(['a', 'b'])` (any). Menu items and buttons hide when not allowed; the server checks again.
- **Branch**: `useCurrentBranch(me)` (`lib/branch.ts`) – one shared choice for the whole app (sidebar branch switcher, room board, calendar, walk-in, housekeeping…), saved on the device.
- Optional modules: `me.hotel.modules`; switches: `me.switches`.
- **No access**: a screen the role may not open (opened by URL) shows `pages/NoAccessPage.tsx` ("You can't open this page" + Go to the start page) – from `RequirePermission`, `RequireSuperAdmin` (`app/router.tsx`; a Super Admin page with a team permission in `adminNav` also needs that permission, same rule as the sidebar; Reports needs one of `REPORTS_PERMISSIONS`; adding staff needs `staff.manage`) and a settings section the role may not use (`SettingsLayout`). A wrong settings name still opens the first allowed section.

## Main helpers

| File | What |
|---|---|
| `lib/decimal.ts` | Exact money maths in the browser (BigInt cents): `addMoney`, `subMoney`, `compareMoney`, `isPositive`, `isNegative` Never `Number` for money |
| `lib/format.ts` | `money()`, `date()`, `dateTime()` in the hotel's style |
| `lib/front.ts` | `localDate`, `localTime`, `today()`, `addDays`, `sleepingOn` (who has a room on a day) |
| `lib/roomFilter.ts` | Room board filters and sort (pure functions, tested) |
| `lib/guides.ts` | Staff guide topics and who sees them |
| `lib/settingsSearch.ts` | Settings search (command menu and the settings page) |
| `lib/offline.ts` | Read-only offline copy for reception |
| `lib/brand.tsx` | Platform name, logo, colour from `/api/v1/branding` |
| `lib/theme.tsx` | Light / dark |
| `lib/cn.ts` | `cn()` = `clsx` + `tailwind-merge` (a later class wins over an earlier one of the same kind) |
| `lib/useIsMobile.ts` | Below 768px the sidebar is a drawer |
| `lib/photos.ts` | Shrinks photos before upload |

## Dashboards (P18)

- **Hotel dashboard** `pages/DashboardPage.tsx` + `pages/dashboard/` (Overview, Revenue, Operations tabs; `DeskLists`, `Sheets`).
  The period and branch live in the address (`?range=30&branch=2&tab=revenue`, `useDashFilters` in `lib/dashboard.ts`), so every widget
  updates at once; every dashboard question's key starts with `'dash'`, so the refresh button reloads them all.
- **Charts** `components/charts/` (Recharts, the template's library): `kit.tsx` (card with loading / empty / error states, `WidgetBoundary`
  so one broken widget never breaks the page, `Legend`, `BarList`), `charts.tsx` (`OccRevenueChart`, `RevenuePaymentsChart`, `Donut`,
  `PaceChart`, `Sparkline`), `KpiCard.tsx`. Colours: `--chart-1` = brand colour, `--chart-2…5`, `--chart-soft`, `--chart-grid`, and the room
  status colours (`ROOM_COLORS`). Animations are off with "reduce motion". Recharts is only imported by dashboard screens (lazy routes).
- **Details in place**: a KPI, a chart day, a room status or a list row opens a side sheet (`pages/dashboard/Sheets.tsx`). Check in,
  check out and take payment use the SAME panels as the booking and bill pages (`CheckInPanel`, `CheckOutPanel`, `PaymentForm`, `PayPanel`,
  loaded only when a sheet opens), so the rules and the "saved once" keys are the same. No new business logic on the dashboard.
- **Module overviews**: `pages/modules/ModuleOverviews.tsx` (cashier: my drawer and short / over of the last closed shifts; housekeeping: rooms by status, tasks today, who is cleaning) and `pages/reports/ReportsOverview.tsx` (the Reports landing for roles with the revenue or occupancy report: KPIs with change, one card per report).
- **Who sees what**: money widgets need `dashboard.view_revenue` (the server never sends money to other roles, `/dashboard/trend` included);
  report widgets also need the report permission; desk lists need `frontdesk.view`; approvals `approvals.decide`.

## Offline (decisions.md W6)

Internet drops about weekly at some hotels. Reception keeps a **read-only** copy on the device (who you are, today's
arriving/leaving lists, in-house guests with phones, the room board). A banner says "You're offline"; changes are refused
with a message and forms keep what was typed. The copy belongs to the signed-in person and is wiped on sign-out.

## Writing a screen

1. Add the page in `pages/<area>/`, the import in `app/prefetch.ts`, the route in `app/router.tsx`, the menu item (if any) in `app/navigation.ts` with its `permission`.
2. Data: a hook in `lib/<area>.ts`.
3. Text: keys in `i18n/locales/en.json` (check there is no duplicate key – a test does not catch it).
4. UI: kit components and colour tokens only; `min-h-touch` targets; light and dark.
5. A test in `test/` for any rule on the screen.
6. If staff use it: a topic in the staff guide (`lib/guides.ts` + `en.guide.json`).

## Commands

```sh
npm run dev        # dev server (Docker does this)
npm test           # vitest
npm run lint       # eslint
npm run typecheck  # tsc
npm run build      # production build (deploy/package.sh uses it)
```
