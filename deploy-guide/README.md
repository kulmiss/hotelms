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

## Found in the project while writing this guide (not changed – owner decides)

1. **Staff-guide pictures are not in the package.** `deploy/package.sh` copies `frontend/dist/assets` and the
   top-level files of `frontend/dist`, but not the `frontend/dist/guide/` folder, so Help → How to use shows no
   pictures on a live server. The guides add a manual copy step. Fix: one line in `deploy/package.sh`
   (`cp -R frontend/dist/guide "$OUT/public/guide"`).
2. **The package copies the local `backend/storage` folder** (only `storage/logs` and `storage/app/backups` are left
   out), so a package built from a development folder can contain test uploads, cached views or session files.
   The guides build from a fresh `git clone`. Fix: leave out the contents of `storage/` except the empty folders.
3. **`docs/hosting.md`'s Nginx example has no "no-cache" for `app.html` and `sw.js`** (the Apache `.htaccess` has it).
   Without it staff can keep an old version of the app after an update. `files/nginx/hotelms.conf` adds it.
4. **No trusted proxies are set** (`bootstrap/app.php`). That is fine for the setups in this guide (the web server
   talks to PHP directly). If you ever put a proxy or CDN in front (e.g. Cloudflare), sign-in limits would count all
   users as one IP address and HTTPS detection would need the proxy to be trusted.
5. **The Docker development image keeps PHP's default upload limit (2 MB)** (`backend/docker/php-dev.ini`), so uploads
   between 2 and 8 MB fail on a developer laptop. Production is covered by `files/php/99-hotelms.ini` and the cPanel
   steps.
6. **`gd` is listed as required in `docs/hosting.md`** but no code uses it today. The guides call it optional.
