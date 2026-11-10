# HotelMS – notes for Claude

Multi-tenant hotel SaaS for Ethiopia. Plans and decisions live in the claude.ai Project "HotelMS" (decisions.md, database-schema.md, api-outline.md, permissions.md, build-plan.md…). Follow them; ask the owner before deciding anything not covered.

## Working rules
- Work on a branch, open a PR, owner merges. Never push to `main`.
- Keep owner-facing messages short and simple (owner is not a developer).
- **Long task lists (owner):** go on part after part without stopping; note every question and ask them all as one list at the end (with Claude's recommendation for each).
- Free options only unless the owner approves a cost.
- **Update reminder (owner):** every message that reports a PR ends with the commands to get it after merging:
  `git checkout main`, `git pull`, `docker compose up -d --build`, `docker compose exec backend php artisan migrate` (say when the migrate step is needed, and any other one-time step).
  PRs target `main` (the restyle branch `ui/shadcn-admin` was merged into `main` on 10 Nov 2026 and is finished).
- **Progress stays current (owner):** what is done, what is next, open questions and decisions Claude made live in the Project's `handover.md`. Read it at the start of a session; update it after every merged PR. Business rules: `workflows.md` (Q1…).
- **Docs stay current (owner):** every PR that changes behaviour also updates the developer docs in `docs/` (start at `docs/README.md`) and, when staff see the change, the staff guide (Help → How to use: `frontend/src/lib/guides.ts` + `frontend/src/i18n/locales/en.guide.json`, very easy English). New business rules also go to the Project's `workflows.md`.

## Stack
- `backend/`: Laravel 13, PHP 8.3, MySQL 8.4, Redis (optional), Sanctum. API under `/api/v1`, JSON only.
- `frontend/`: React 19 + TypeScript + Vite, Tailwind v4, React Router, TanStack Query, react-i18next.

## Conventions
- **Tenancy in code:** hotel-owned models use the `BelongsToHotel` trait (auto-scope + auto-fill `hotel_id`, fails closed when no hotel is set). The hotel comes from the signed-in user via the `tenant` middleware, never from the client. Jobs/seeders use `TenantContext::runAs($hotel, fn)`; Super Admin platform views use `acrossAllHotels(fn)`. `User` is not scoped (login is platform-wide) — filter staff lists by `hotel_id`.
- **Tenancy:** every hotel-owned table has `hotel_id` (and `branch_id` where relevant); queries are always scoped. A data leak between hotels is the worst possible bug — test it.
- **Money:** `decimal(14,2)` + currency code in the database (as approved in database-schema.md); strings in JSON (`"4662.50"`); never floats in PHP/JS maths.
- **Strings:** every UI string goes through `t()` (`frontend/src/i18n/locales/en.json`).
- **Design (owner choice, 8 Nov 2026):** the look and feel of **shadcn-admin** (template `satnaing/shadcn-admin` @ `e16c87f`, MIT – see `THIRD_PARTY_NOTICES`; plan and progress in `MIGRATION_PLAN.md`). Slate colours (OKLCH), Inter, **Lucide icons**, shadcn cards (`.panel` = rounded-xl, border, small shadow), sidebar inset + collapsible to icons with tooltips, header with ⌘K search, 150–200ms transitions. Use the colour tokens in `frontend/src/index.css`, never raw hex. Our names map to shadcn's: `bg`=background, `surface`=card, `sunken`=muted/accent (hover, active), `text`=foreground, `muted`=muted-foreground, `accent`=primary (**the Super Admin brand colour**), `ring`=card outline, `ring-strong`=input, `focus`=focus ring. Light and dark must both work. Desktop uses the template's sizes (14px text, 16px icons, 32–36px controls); **touch screens keep 44px tap targets** (`min-h-touch`, `pointer: coarse`). The brand colour, name and logo come from Super Admin branding (`/api/v1/branding`, `lib/brand.tsx`) – never hard-code "HotelMS" in the UI. Printed invoices and registration cards keep their own black-on-white look.
- **Speed (owner priority):** screens are lazy-loaded and prefetched on hover (`app/prefetch.ts`); CSS-only motion; no heavy UI libraries.
- **Below 1024px:** filters, sort and toolbar controls fold behind one **Filters** button (`CollapsibleToolbar`, used by `DataTable`, `ResourceList`, the room board). Two-column screens use `minmax(0,1fr)` for the main column. A screen the role may not open shows `NoAccessPage` (never a silent redirect).
- **Touch:** every tap target ≥ 44px on touch screens (`min-h-touch` / `size-touch`); main reception actions use `size="lg"` (52px).
- **Hosting (owner decision):** code must run on **shared hosting (cPanel) and a VPS**. Redis is optional (database drivers must work); background work runs from cron (`schedule:run` every minute, queues with `--stop-when-empty`) — no always-on worker; live screens poll, no WebSocket server; Docker is for development only.
- **Tests:** every business rule gets a test. Backend: `php artisan test`. Frontend: `npm test`.
- **Style:** backend `vendor/bin/pint`; frontend `npm run lint`.
