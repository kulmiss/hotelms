# Run it on your laptop

## Start

Needs Docker Desktop (on Windows: WSL integration for Ubuntu turned on).

```sh
cd ~/hotelms
git pull
docker compose up --build     # first time, or after dependency changes
docker compose up             # other times
```

| Service | Address | What |
|---|---|---|
| frontend | http://localhost:5173 | React dev server (hot reload) |
| backend | http://localhost:8000/api/v1/health | Laravel (`php artisan serve --no-reload`, 8 workers) |
| scheduler | – | runs `php artisan schedule:work` (the cron jobs) |
| mysql | localhost:3306 | MySQL 8.4 (data in the `mysql-data` volume) |
| redis | localhost:6379 | optional |
| mailpit | http://localhost:8025 | every email the app sends lands here |

The backend answers 8 requests at the same time (`PHP_CLI_SERVER_WORKERS`). Laravel only does that with `--no-reload`,
so it no longer restarts by itself when `backend/.env` changes: run `docker compose restart backend` after editing `.env`.
(Code changes still show at once.)

The `docs/` folder and `deploy-guide/pdf/` are mounted into the backend container (read-only), so Super Admin → Developer docs shows your latest edits and the newest guide PDFs (after editing `docker-compose.yml` mounts: `docker compose up -d`).

Sign in as the Super Admin with `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD` from `backend/.env`
(you are asked to choose your own password the first time).

## Running commands

PHP is inside the container, not on your laptop:

```sh
docker compose exec backend php artisan migrate        # after pulling new migrations
docker compose exec backend php artisan demo:reset     # rebuild the demo hotel (60 rooms, a year of bookings)
docker compose exec backend php artisan demo:reset --rooms=160   # a big hotel, to feel the speed
docker compose exec backend php artisan test           # backend tests (SQLite in memory by default)
docker compose exec backend vendor/bin/pint            # code style (backend)
docker compose exec frontend npm test                  # frontend tests
docker compose exec frontend npm run lint
docker compose exec frontend npm run typecheck
```

The demo hotel runs no automations (no messages), so the reset also makes **approvals waiting** to try: two or three price suggestions and, when there is a cancelled paid booking, a refund (`DemoHistory::approvals`).

Other artisan commands: see [backend.md → Commands](backend.md#commands).

## The demo hotel

"Sheger Demo Hotel" (`is_demo = true`): 2 branches, 60 rooms, 1,500 guests, a busy year of bookings, a staff rota,
cashier shifts and cleaning tasks. Built by `app/Services/DemoHotel.php` + `app/Services/Demo/DemoHistory.php` and
`DemoTeam.php`. It is rebuilt every night at 02:30 (dates move with today) and from Super Admin → Hotels → demo → Reset.
Demo users: `demo.admin@`, `demo.manager@`, `demo.reception@`, `demo.housekeeping@`, `demo.accountant@hotelms.demo`
(password in `DemoHotel::PASSWORD`). The public "Try the demo" login is on when the platform setting `demo_public` is on. A visit lasts `demo_minutes` (Super Admin → Settings, default 30): the sign-in stores `demo_until` in the session, `DemoTimeLimit` (api group) signs the visitor out after it (401, code `demo_over`), and the banner counts down. Super Admin "log in as hotel" is not limited.
The demo never sends emails or Telegram messages, and is left out of platform reports.

## Checks (CI)

Four workflows in `.github/workflows/`. The first three run on a pull request **only when their files change** (to save
GitHub Actions minutes – a PR that changes only documentation runs none of them). Each can also be started by hand:
GitHub → Actions → the workflow → **Run workflow**. Nothing runs again after a merge to `main` (the PR was tested).

1. **Backend** (`backend.yml`, when `backend/` changes; on MySQL 8.4): `composer audit`, Pint (fixes style and pushes
   a "Code style (fixed by CI)" commit on PRs – run `git pull --rebase` before your next push), migrations back and
   forward, `php artisan test`, then the **speed test** `php artisan test tests/Performance` (big hotel; prints the
   slowest screens and the slowest database query of each).
2. **Frontend** (`frontend.yml`, when `frontend/` changes): `npm audit`, lint, typecheck, `vitest`, build, first-download size.
3. **Package** (`package.yml`, when `deploy/`, `frontend/`, `backend/public/` or `backend/composer.*` change): builds
   `hotelms.zip` with `deploy/package.sh` and checks it (staff-guide pictures in, work-folder files out); the zip is
   kept 7 days (the run → Artifacts → `hotelms-package`). Also `deploy/check-csp.sh` (the app page's CSP hash).
4. **Secrets** (`secrets.yml`, every PR, a few seconds): gitleaks on the PR's commits only; a key, token or password
   fails it. Known test data that looks like a secret: `.gitleaksignore`.

A PR is merged only when all checks are green. Never push to `main`: work on a branch, open a PR, the owner merges.

## Tests: what to add

- **Every business rule gets a test** (backend feature test that calls the API like the app does).
- New hotel data or routes: make sure `IsolationSweepTest` covers them (another hotel gets 404/403 and sees nothing).
- **New routes: `AccessMatrixTest` fails until the route's protection is known** – `permission:…` / `platform:…`
  middleware (then every role without it is tried and must get 403), or the short `PUBLIC` / `INSIDE` lists in the test
  (checked inside the controller: give it its own test).
- New screen logic with rules (filters, money maths): a small frontend test (`frontend/src/test/*.test.ts(x)`), using
  `mockApi` and `renderApp` from `test/mockApi.ts` and `test/renderApp.tsx`.
- Time-dependent rules: `$this->travelTo(Carbon::parse('2026-11-12 09:00', 'Africa/Addis_Ababa'))`.
