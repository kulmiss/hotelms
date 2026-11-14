# Putting HotelMS online

Same code for **shared hosting (cPanel)** and a **VPS**. No Redis, no always-on worker, no WebSockets needed.

## What the server needs
- PHP **8.3** with: `pdo_mysql`, `bcmath`, `mbstring`, `openssl`, `zip`, `zlib`, `intl`, `fileinfo` (`gd` is optional: no code uses it today; Laravel only suggests it)
- PHP limits: `upload_max_filesize` ≥ 9M and `post_max_size` ≥ 10M (payment proofs and guest ID photos can be 8 MB; PHP's default is 2 MB)
- MySQL **8** (or MariaDB 10.6+)
- HTTPS (free Let's Encrypt / AutoSSL in cPanel)
- A cron job (every minute)
- Data law: guest data must stay on servers **in Ethiopia** (Proclamation 1321/2024) – choose an Ethiopian host.

## 1. Build the package (on your laptop)

The package also holds the `docs/` folder and the deployment guide PDFs (`deploy-guide/pdf/`), both outside `public/`, so not on the web: Super Admin → Developer docs reads them.
Another place: set `DOCS_PATH` / `DEPLOY_GUIDE_PATH` in `.env`.
```sh
./deploy/package.sh        # makes build/hotelms.zip
```
Only code goes into the package: the web app (`app.html`, `assets/`, the staff-guide pictures in `guide/`, icons,
`sw.js`), the backend with `vendor/` (no developer packages) and `docs/`. Never your `.env`, uploads, logs, sessions,
caches, tests or the development Docker files – `storage/` and `bootstrap/cache/` are created empty. CI builds the package on every pull request that
touches the app or the package script, checks exactly this (workflow "Package") and keeps the zip for 7 days (Actions → the run → Artifacts → `hotelms-package`).

## 2a. Shared hosting (cPanel)
1. **MySQL Databases:** create a database + user, give the user ALL PRIVILEGES.
2. **File Manager:** upload `hotelms.zip` to your home folder (NOT `public_html`) and extract → `/home/USER/hotelms`.
3. **Domains:** set the document root of your (sub)domain to `/home/USER/hotelms/public`.
   *If your host does not allow that:* move the contents of `hotelms/public` into `public_html` and in `public_html/index.php` change the two `__DIR__.'/../` paths to `__DIR__.'/../hotelms/`.
4. Copy `.env.production.example` to `.env` and fill it in (database, mail, domain).
5. **Terminal** (or "Setup PHP App"):
   ```sh
   cd ~/hotelms
   php artisan key:generate        # once! keep a copy of APP_KEY in a safe place
   php artisan migrate --force
   php artisan db:seed --force     # creates the Super Admin
   php artisan storage:link
   php artisan optimize            # caches settings, routes, events and views: much faster
   ```
6. **Cron Jobs** → every minute:
   ```
   * * * * * cd /home/USER/hotelms && php artisan schedule:run >> /dev/null 2>&1
   ```
   This runs everything in the background: expiring bookings, reports, alerts, Telegram, backups, and the clean-up of
   saved-once keys (`idempotency_keys`: kept 24 h, removed after 48 h). Without it those keys pile up (harmless, but the
   table grows). Super Admin → System shows the scheduler green when it runs. (Docker for development runs it in the
   `scheduler` service.)
7. **SSL/TLS** → AutoSSL on. Open `https://your-domain/` and sign in as the Super Admin.

## 2b. VPS (Ubuntu 24.04)
```sh
sudo apt install nginx mysql-server php8.3-fpm php8.3-{mysql,bcmath,mbstring,xml,zip,intl,gd,curl,opcache,redis} redis-server unzip
sudo mkdir -p /var/www && sudo unzip hotelms.zip -d /var/www && sudo chown -R www-data:www-data /var/www/hotelms
```
Then steps 4–5 above (in `/var/www/hotelms`), the cron line for user `www-data` (`sudo crontab -u www-data -e`), and this nginx site:
```nginx
server {
    listen 80;
    server_name app.example.et;
    root /var/www/hotelms/public;
    index app.html index.php;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Permissions-Policy "camera=(self), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()" always;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    client_max_body_size 10m;
    gzip on;
    gzip_comp_level 5;
    gzip_types text/plain text/css application/javascript application/json application/manifest+json image/svg+xml;

    location ~ ^/(api|sanctum|up)(/|$) { try_files $uri /index.php?$query_string; }
    location /assets/ { expires 1y; add_header Cache-Control "public, immutable" always; add_header X-Content-Type-Options nosniff always; }
    # The app page and the offline copy (service worker): never cached, or staff keep an old version (as .htaccess does).
    # + the Content-Security-Policy (its hash must match the <script> in frontend/index.html; deploy/check-csp.sh checks it).
    location = /app.html { add_header Cache-Control "no-cache" always; add_header X-Content-Type-Options nosniff always; add_header X-Frame-Options DENY always; add_header Referrer-Policy strict-origin-when-cross-origin always; add_header Permissions-Policy "camera=(self), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()" always; add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always; add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'sha256-z/AEeak/K/SDD4clgB/AxZmh9ukx5WqWeS32LG/Ze8M='; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src blob:; worker-src 'self'; manifest-src 'self'; media-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'" always; }
    location = /sw.js { add_header Cache-Control "no-cache" always; add_header X-Content-Type-Options nosniff always; }
    # Uploaded logos: pictures only, never run as a page.
    location /storage/ { add_header Content-Security-Policy "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" always; add_header X-Content-Type-Options nosniff always; }
    location / { try_files $uri $uri/ /app.html; }  # every app address (e.g. /reservations/12) opens app.html
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```
`sudo certbot --nginx -d app.example.et` for HTTPS. Optional upgrade later: Redis (`CACHE_STORE=redis`, `SESSION_DRIVER=redis`).

## Updating to a new version
```sh
php artisan down
# upload/extract the new package over the old folder (keep .env and storage/)
php artisan migrate --force && php artisan optimize
php artisan up
```
A backup is taken every hour, so you can always go back (see below).
When a new version adds tables, the `migrate` step is **required** (for example the saved-once update adds `idempotency_keys`).
Development (Docker): `docker compose exec backend php artisan migrate`.

## Speed
- **PHP OPcache on** (cPanel → *Select PHP Version* → *Extensions*: tick `opcache`; *Options*: `opcache.memory_consumption` 128 or more, `opcache.max_accelerated_files` 20000). On a VPS it is on by default with `php8.3-fpm`.
- **Redis** if the host offers it (`CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `CACHE_LIMITER=redis`); otherwise the database drivers work. Rate-limit counters use files by default (`CACHE_LIMITER=file`, fast on shared hosting; `storage/framework/cache` must be writable).
- After every update run `php artisan optimize` (above). Never leave `APP_DEBUG=true` on a live server: it is slow and shows internals.
- Text is compressed (gzip/brotli) and the hashed files in `assets/` are cached for a year by `public/.htaccess` (`app.html` and `sw.js` never); on Nginx use the `gzip on;` lines in the VPS config above.

## Secrets and server settings
- Secret settings typed in Super Admin (mail password, Telegram bot token) are stored **encrypted** with `APP_KEY` and never sent back in full.
- The mail server typed in Super Admin → Settings must be an **internet** address: `localhost`, `127.0.0.1`, `10.x`, `172.16–31.x`, `192.168.x`, link-local and names that point there are refused (SSRF). A mail server on the same machine: put it in `.env` (`MAIL_HOST`), or set `MAIL_ALLOW_PRIVATE_HOST=true`.
- Super Admin → System → **Server settings** turns red (and the watchdog warns) when `APP_DEBUG=true`, `APP_ENV` is not `production`, `SESSION_SECURE_COOKIE` is not `true`, or `APP_URL` is not `https://`. (On a development computer it is red on purpose.)
- `GET /api/v1/health` shows the version only to the HotelMS team.

## Security headers
Sent by `public/.htaccess` (Apache / cPanel) and the Nginx example; Laravel adds the same safe defaults to every API answer (`App\Http\Middleware\SecurityHeaders`).
- **App page (`app.html`): a strict Content-Security-Policy.** Scripts only from the site itself plus the one inline theme script in `frontend/index.html`, allowed by its `sha256` hash; no plugins (`object-src 'none'`), no framing (`frame-ancestors 'none'`), data only from the site (`connect-src 'self'`), the guide PDFs from `blob:`. Styles may be inline (the charts need it). **When you change that inline script, the hash changes:** `./deploy/check-csp.sh` prints the new one and fails until `.htaccess`, the Nginx example and the snippet above match (CI runs it in the Package job).
- **API answers:** `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` on JSON (files keep their own headers).
- **Everywhere:** `Strict-Transport-Security: max-age=63072000; includeSubDomains` (HTTPS only, 2 years – not "preload"; add that only on purpose, it is hard to undo), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (no microphone, location, payment, USB; camera only for this site).
- **CORS** (`config/cors.php`): only `APP_URL` / `FRONTEND_URL`, with the sign-in cookie; every other origin gets no access.
- **`/.well-known/security.txt`** (from `frontend/public/`): how to report a security problem (info@kulmiss.com). It has an `Expires` date: renew it before **14 Nov 2027**.

## Backups
- Every night a full backup, every hour a small one (without logs) – in `BACKUP_PATH` (kept 30 days / 48 hours).
- Set `BACKUP_COPY_PATH` to a second disk or a folder synced to another location in Ethiopia.
- Every night the uploaded files (payment proofs and the encrypted guest ID photos, in `storage/app/private`) are copied to `BACKUP_PATH/files` (and the second copy). Files the hotels deleted are removed there too. To restore them, copy that `files` folder back into `storage/app/private`.
- On the 1st of every month the newest backup is opened and checked; if something is wrong the HotelMS team gets an email.
- **Encrypted** (libsodium XChaCha20-Poly1305, streamed) with `BACKUP_KEY` – a key of its own, not `APP_KEY`: files end in `.sql.gz.enc`, the mirrored upload copies in `.enc`. Make the key once with `php artisan hotelms:backup-key`, put the line in `.env` and **keep a copy in your password manager**. A live server (`APP_ENV=production`) makes **no** backup without it (System and the watchdog turn red); in development an empty key gives plain `.sql.gz` files. Older plain backups can still be checked and restored.
- By hand: `php artisan hotelms:backup` · check: `php artisan hotelms:backup --check`
- **Restore** (replaces ALL data): `php artisan hotelms:backup --restore=/path/file.sql.gz.enc` (needs the same `BACKUP_KEY`)
- A readable copy of one encrypted file (e.g. a payment proof from `files/`): `php artisan hotelms:backup --decrypt=/path/file.enc --to=/tmp/out` – delete it after use.
- Restoring needs **both keys**: `BACKUP_KEY` to open the file, `APP_KEY` for the guest ID numbers, two-step secrets and ID photos inside it. Keep both safe, separately from the server.

## Rescue
- Super Admin lost the phone for two-step sign-in: `php artisan hotelms:reset-two-factor owner@example.et`
- Super Admin password: `php artisan tinker` → `App\Models\User::where('email','owner@example.et')->first()->forceFill(['password'=>'New-Pass-123','must_change_password'=>true])->save();`

## Go-live checklist (first launch and every big update)

1. **Backup first:** `php artisan hotelms:backup` (and check it: `php artisan hotelms:backup --check`). `BACKUP_KEY` is set (backups are encrypted; System → Backups says "encrypted"). Keep copies of `APP_KEY` and `BACKUP_KEY` in your password manager.
2. **Put the new code on the server** (section 2a / 2b), then **migrate:** `php artisan migrate --force`, then `php artisan optimize`.
3. **Cron:** the `schedule:run` line runs every minute (cPanel: section 2a step 6; VPS: `www-data` crontab). Check Super Admin → System: Scheduler green.
4. **LICENSE:** the owner's name / company and email are filled in (no `[OWNER …]` placeholders).
   **Headers:** `curl -sI https://<domain>/` shows `Content-Security-Policy` and `Strict-Transport-Security`; `https://<domain>/.well-known/security.txt` opens.
5. **What's new:** publish the note for staff (Super Admin → Releases; draft in `docs/drafts/`).
6. **Code:** the final PR `ui/shadcn-admin → main` is merged; the server runs `main`.
7. **Smoke test:** sign in as each role on a phone and a computer; take a test payment in the demo hotel.
8. **Dashboards:** open the dashboard as Hotel Admin (charts show, the data appears in about 1 s) and as Receptionist (no money anywhere on it). Super Admin → Command center shows real hotels only (the demo is left out).

### Rollback plan
- **Code:** put the previous package back (keep the last `hotelms.zip`), `php artisan optimize`.
- **Database:** only if the new version added tables: `php artisan migrate:rollback --step=1 --force` undoes the last migration (CI checks that every new migration can go back and forward). The saved-once table (`idempotency_keys`) can be dropped safely: it only holds answers for 24 h.
- **Data problem:** restore the backup from step 1: `php artisan hotelms:backup --restore=/path/file.sql.gz`.

## Behind a proxy or CDN

The setups above let the web server talk to PHP directly, so Laravel sees each visitor's real IP address (sign-in
limits, activity log) and HTTPS by itself. Only if you put a proxy, load balancer or CDN (e.g. Cloudflare) in front,
set `TRUSTED_PROXIES` in `.env` to its addresses (comma separated, CIDR allowed; `*` only when the server cannot be
reached except through the proxy), then `php artisan optimize`. Empty (the default) trusts nobody, so nobody can fake
an IP address with an `X-Forwarded-For` header (`App\Support\TrustedProxies`, tests in `TrustedProxiesTest`).

