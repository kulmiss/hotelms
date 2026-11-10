# HotelMS deployment guide

A standalone guide for putting HotelMS online. It lives **outside the app code**: `deploy/package.sh` packs only
`backend/` and `docs/`, so nothing in this folder is ever uploaded to a server or changes the app.

| File | What |
|---|---|
| [index.md](index.md) · [pdf/index.pdf](pdf/index.pdf) | Which option to choose: shared hosting or VPS (requirements, pros, cons, cost, questions for hosts). |
| [shared-hosting.md](shared-hosting.md) · [pdf/shared-hosting.pdf](pdf/shared-hosting.pdf) | cPanel / shared hosting, step by step (also without SSH). |
| [vps.md](vps.md) · [pdf/vps.pdf](pdf/vps.pdf) | VPS with Ubuntu 24.04 LTS: security basics, option A Docker, option B manual, updates, backups, monitoring. |
| `files/nginx/hotelms.conf` | Nginx site (VPS). |
| `files/php/99-hotelms.ini` | PHP settings (upload limits, OPcache). |
| `files/docker/` | Production Dockerfile + compose file for VPS option A (untested – see vps.md). |
| `files/scripts/deploy.sh` | Install / update / rollback on a VPS with release folders (tested in manual mode). |
| `files/scripts/offsite-backup.sh` | Copies the app's backups to a second place with rclone. |
| `files/systemd/`, `files/supervisor/` | Scheduler timer (alternative to cron) and an optional queue worker. |
| `images/` | Diagrams used in the guides. |
| `build-pdf.sh`, `pdf.css` | Rebuild the PDFs: `./deploy-guide/build-pdf.sh` (needs pandoc and Chrome/Chromium). |

Placeholders look like `<YOUR_DOMAIN>` and are listed at the top of each guide. No real secrets, IPs or customer
data are in this folder. Things the guide cannot know about your host are marked **CHECK**.

## Found in the project while writing this guide – all fixed in #129

1. **Staff-guide pictures were not in the package** – `deploy/package.sh` now copies every folder of the web build
   (`assets/`, `guide/`).
2. **The package copied the local `backend/storage` folder** – now it never takes `.env`, uploads, logs, sessions,
   caches, `bootstrap/cache/*.php` or tests from the work folder; `storage/` is created empty. A CI job ("Package")
   plants such files and fails if any of them ends up in the zip.
3. **No "no-cache" for `app.html` and `sw.js` in the Nginx example of `docs/hosting.md`** – added. Also: `.htaccess`
   cached every picture for a year (also the staff-guide pictures and icons, whose names never change) – now only
   `assets/`.
4. **No trusted proxies** – new opt-in `TRUSTED_PROXIES` in `.env` (empty = trust nobody, as before). Trusting
   everyone by default would let anyone fake their IP address.
5. **Dev Docker upload limit 2 MB** – `backend/docker/php-dev.ini` now allows 9M / 10M.
6. **`gd` listed as required** – now "optional" in `docs/hosting.md` (no code uses it).

Also found and fixed in #129: `backend/.env.production.example` was never in Git (hidden by the `.env.*` rule in
`.gitignore`), so a fresh clone did not have it. It is tracked now.
