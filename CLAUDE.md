# HotelMS – notes for Claude

Multi-tenant hotel SaaS for Ethiopia. Plans and decisions live in the claude.ai Project "HotelMS" (decisions.md, database-schema.md, api-outline.md, permissions.md, build-plan.md…). Follow them; ask the owner before deciding anything not covered.

## Working rules
- Work on a branch, open a PR, owner merges. Never push to `main`.
- Keep owner-facing messages short and simple (owner is not a developer).
- Free options only unless the owner approves a cost.
- **Docs stay current (owner):** every PR that changes behaviour also updates the developer docs in `docs/` (start at `docs/README.md`) and, when staff see the change, the staff guide (Help → How to use: `frontend/src/lib/guides.ts` + `frontend/src/i18n/locales/en.guide.json`, very easy English). New business rules also go to the Project's `workflows.md`.

## Stack
- `backend/`: Laravel 13, PHP 8.3, MySQL 8.4, Redis (optional), Sanctum. API under `/api/v1`, JSON only.
- `frontend/`: React 19 + TypeScript + Vite, Tailwind v4, React Router, TanStack Query, react-i18next.

## Conventions
- **Tenancy in code:** hotel-owned models use the `BelongsToHotel` trait (auto-scope + auto-fill `hotel_id`, fails closed when no hotel is set). The hotel comes from the signed-in user via the `tenant` middleware, never from the client. Jobs/seeders use `TenantContext::runAs($hotel, fn)`; Super Admin platform views use `acrossAllHotels(fn)`. `User` is not scoped (login is platform-wide) — filter staff lists by `hotel_id`.
- **Tenancy:** every hotel-owned table has `hotel_id` (and `branch_id` where relevant); queries are always scoped. A data leak between hotels is the worst possible bug — test it.
- **Money:** `decimal(14,2)` + currency code in the database (as approved in database-schema.md); strings in JSON (`"4662.50"`); never floats in PHP/JS maths.
- **Strings:** every UI string goes through `t()` (`frontend/src/i18n/locales/en.json`).
- **Design (owner choice):** Filament-style panel UI: zinc greys, teal primary, Inter, Heroicons (outline; solid when active), `.panel` cards (white, fine ring, small shadow), quick 75–200ms transitions. Use the colour tokens in `frontend/src/index.css` (`bg-surface`, `text-muted`, `bg-accent`, `ring-ring`…), never raw hex. Light and dark must both work. Collapsed sidebar = icons only with tooltips. Sleek sizes: 13px UI text, 16–20px icons, `min-h-touch` is 36px with a mouse and 44px on touch screens (`pointer: coarse`). The accent colour, name and logo come from Super Admin branding (`/api/v1/branding`, `lib/brand.tsx`) – never hard-code "HotelMS" in the UI.
- **Speed (owner priority):** screens are lazy-loaded and prefetched on hover (`app/prefetch.ts`); CSS-only motion; no heavy UI libraries.
- **Touch:** every tap target ≥ 44px (`min-h-touch` / `size-touch`); main reception actions use `size="lg"` (52px).
- **Hosting (owner decision):** code must run on **shared hosting (cPanel) and a VPS**. Redis is optional (database drivers must work); background work runs from cron (`schedule:run` every minute, queues with `--stop-when-empty`) — no always-on worker; live screens poll, no WebSocket server; Docker is for development only.
- **Tests:** every business rule gets a test. Backend: `php artisan test`. Frontend: `npm test`.
- **Style:** backend `vendor/bin/pint`; frontend `npm run lint`.
