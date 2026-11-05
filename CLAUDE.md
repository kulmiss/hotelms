# HotelMS – notes for Claude

Multi-tenant hotel SaaS for Ethiopia. Plans and decisions live in the claude.ai Project "HotelMS" (decisions.md, database-schema.md, api-outline.md, permissions.md, build-plan.md…). Follow them; ask the owner before deciding anything not covered.

## Working rules
- Work on a branch, open a PR, owner merges. Never push to `main`.
- Keep owner-facing messages short and simple (owner is not a developer).
- Free options only unless the owner approves a cost.

## Stack
- `backend/`: Laravel 13, PHP 8.3, MySQL 8.4, Redis (optional), Sanctum. API under `/api/v1`, JSON only.
- `frontend/`: React 19 + TypeScript + Vite, Tailwind v4, React Router, TanStack Query, react-i18next.

## Conventions
- **Tenancy:** every hotel-owned table has `hotel_id` (and `branch_id` where relevant); queries are always scoped. A data leak between hotels is the worst possible bug — test it.
- **Money:** integer minor units + currency code; never floats.
- **Strings:** every UI string goes through `t()` (`frontend/src/i18n/locales/en.json`).
- **Design:** use the colour tokens in `frontend/src/index.css` (`bg-surface`, `text-muted`, `bg-accent`…), never raw hex. Light and dark must both work.
- **Touch:** every tap target ≥ 44px (`min-h-touch` / `size-touch`); main reception actions use `size="lg"` (52px).
- **Hosting (owner decision):** code must run on **shared hosting (cPanel) and a VPS**. Redis is optional (database drivers must work); background work runs from cron (`schedule:run` every minute, queues with `--stop-when-empty`) — no always-on worker; live screens poll, no WebSocket server; Docker is for development only.
- **Tests:** every business rule gets a test. Backend: `php artisan test`. Frontend: `npm test`.
- **Style:** backend `vendor/bin/pint`; frontend `npm run lint`.
