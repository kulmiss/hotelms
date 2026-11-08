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
| `components/shadcn/` | shadcn primitives from the template (Radix UI + cmdk): sidebar, button, dropdown menu, popover, tooltip, dialog, sheet, command, separator. Class names translated to our tokens (see its README) |
| `components/ui/` | The UI kit: `Button`, `Card`, `Field`, `Select`, `Tabs`, `Alert`, `Badge`, `PageHeader`, `DataTable` (every list: sort, 25/50/100 rows, hide columns, Excel, phone cards; the header scrolls with the rows), `DateRangeFilter`, `LoadFailed` (a screen whose data did not load shows "not found" / "not allowed" / "could not load" + Try again, never an empty page; `DataTable` takes `error` + `onRetry` for the same on lists), `ResourceList`, `SmartHints`, `StatTile`… |
| `components/billing`, `payments`, `guests`, `print`, `support` | Pieces shared by several screens (add charge, payment proof, ID photo, print header, support chat) |
| `pages/<area>/` | Screens: `desk/` (room board, walk-in, front desk mode), `reservations/`, `guests/`, `billing/`, `modules/` (cashier, housekeeping, shifts, attendance), `reports/`, `settings/`, `setup/`, `team/`, `admin/` (Super Admin), `help/`, `security/`, `approvals/`, `subscription/` |
| `lib/` | Data hooks and helpers per area (below) |
| `i18n/` | `index.ts`; `locales/en.json` (all screen text), `locales/en.guide.json` (staff guides) |
| `index.css` | Tailwind v4 theme in the **shadcn-admin** look (slate OKLCH tokens, radius 0.625rem; our token names map to shadcn's – see the comment at the top), `.panel` (= shadcn card), touch sizes, focus ring, print rules. Template notice: `THIRD_PARTY_NOTICES`; plan: `MIGRATION_PLAN.md` |
| `test/` | Vitest + Testing Library; `mockApi.ts` (fake API + `makeMe`), `renderApp.tsx` |

## Layout and shortcuts

- **Sidebar** (template, `variant="inset"`, `collapsible="icon"`): 16rem wide, 3rem when collapsed (3.75rem on touch screens so the 44px buttons fit), a drawer below 768px. Collapsed or not is saved on the device (`hms-sidebar-collapsed`); with no saved choice, tablet widths (768–1023px) start collapsed. Collapsed items show their name as a tooltip.
- **Shortcuts**: Ctrl/⌘+K or `/` opens the command menu (search, pages, theme); Ctrl/⌘+B opens/closes the sidebar; Tab from the top shows "Skip to main content".
- **Icons**: Lucide, imported one by one (`import { House } from 'lucide-react'`) so only the used icons are downloaded. Heroicons are still used by screens not restyled yet (MIGRATION_PLAN P3–P16).
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

## Who sees what

- `useMe()` (`lib/auth.ts`): the signed-in person, hotel, branches, role, `permissions`, modules, switches, impersonation.
- `useCan()` (`lib/hotel.ts`): `can('x.y')` or `can(['a', 'b'])` (any). Menu items and buttons hide when not allowed; the server checks again.
- **Branch**: `useCurrentBranch(me)` (`lib/branch.ts`) – one shared choice for the whole app (sidebar branch switcher, room board, calendar, walk-in, housekeeping…), saved on the device.
- Optional modules: `me.hotel.modules`; switches: `me.switches`.

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
