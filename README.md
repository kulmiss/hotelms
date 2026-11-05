# HotelMS

Multi-hotel management system (SaaS) for hotels in Ethiopia.

| Folder | What it is |
|---|---|
| `backend/` | Laravel REST API (`/api/v1`) |
| `frontend/` | React + TypeScript web app |
| `mobile/` | Flutter app (later) |

## Run it on your laptop

Needs: Docker Desktop (with WSL integration for Ubuntu turned on).

In the Ubuntu terminal:

```sh
cd ~/hotelms
git pull
docker compose up --build
```

The first start takes several minutes (it downloads and installs everything). Then open:

- **App:** http://localhost:5173
- **API status:** http://localhost:8000/api/v1/health
- **Emails sent by the app:** http://localhost:8025

**Sign in** as the Super Admin: `admin@hotelms.test` / `ChangeMe-123`. You'll be asked to choose your own password. (Change the email in `backend/.env`: `SUPER_ADMIN_EMAIL`, before the first start.)

Stop it with `Ctrl + C`. Next time, `docker compose up` (without `--build`) is enough.

## Checks

Every pull request runs the backend tests (on MySQL), the code style check, and the frontend lint, type check, tests and build. A PR is only merged when all checks are green.
