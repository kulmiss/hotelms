# Putting HotelMS online

Same code for **shared hosting (cPanel)** and a **VPS**. No Redis, no always-on worker, no WebSockets needed.

## What the server needs
- PHP **8.3** with: `pdo_mysql`, `bcmath`, `mbstring`, `openssl`, `zip`, `zlib`, `intl`, `fileinfo`, `gd`
- MySQL **8** (or MariaDB 10.6+)
- HTTPS (free Let's Encrypt / AutoSSL in cPanel)
- A cron job (every minute)
- Data law: guest data must stay on servers **in Ethiopia** (Proclamation 1321/2024) – choose an Ethiopian host.

## 1. Build the package (on your laptop)

The package also holds the `docs/` folder (outside `public/`, so not on the web): Super Admin → Developer docs reads it.
Another place: set `DOCS_PATH` in `.env`.
```sh
./deploy/package.sh        # makes build/hotelms.zip
```

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
   This runs everything in the background: expiring bookings, reports, alerts, Telegram, backups.
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
    client_max_body_size 10m;
    gzip on;
    gzip_comp_level 5;
    gzip_types text/plain text/css application/javascript application/json application/manifest+json image/svg+xml;

    location ~ ^/(api|sanctum|up)(/|$) { try_files $uri /index.php?$query_string; }
    location /assets/ { expires 1y; add_header Cache-Control "public, immutable"; }
    # Uploaded logos: pictures only, never run as a page.
    location /storage/ { add_header Content-Security-Policy "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" always; add_header X-Content-Type-Options nosniff always; }
    location / { try_files $uri $uri/ /app.html; }
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

## Speed
- **PHP OPcache on** (cPanel → *Select PHP Version* → *Extensions*: tick `opcache`; *Options*: `opcache.memory_consumption` 128 or more, `opcache.max_accelerated_files` 20000). On a VPS it is on by default with `php8.3-fpm`.
- **Redis** if the host offers it (`CACHE_STORE=redis`, `SESSION_DRIVER=redis`); otherwise the database drivers work.
- After every update run `php artisan optimize` (above). Never leave `APP_DEBUG=true` on a live server: it is slow and shows internals.
- Text is compressed (gzip/brotli) and the app's files are cached for a year by `public/.htaccess`; on Nginx use the `gzip on;` lines in the VPS config above.

## Backups
- Every night a full backup, every hour a small one (without logs) – in `BACKUP_PATH` (kept 30 days / 48 hours).
- Set `BACKUP_COPY_PATH` to a second disk or a folder synced to another location in Ethiopia.
- Every night the uploaded files (payment proofs and the encrypted guest ID photos, in `storage/app/private`) are copied to `BACKUP_PATH/files` (and the second copy). Files the hotels deleted are removed there too. To restore them, copy that `files` folder back into `storage/app/private`.
- On the 1st of every month the newest backup is opened and checked; if something is wrong the HotelMS team gets an email.
- By hand: `php artisan hotelms:backup` · check: `php artisan hotelms:backup --check`
- **Restore** (replaces ALL data): `php artisan hotelms:backup --restore=/path/file.sql.gz`
- The backups are useless without the same `APP_KEY` – keep it safe, separately.

## Rescue
- Super Admin lost the phone for two-step sign-in: `php artisan hotelms:reset-two-factor owner@example.et`
- Super Admin password: `php artisan tinker` → `App\Models\User::where('email','owner@example.et')->first()->forceFill(['password'=>'New-Pass-123','must_change_password'=>true])->save();`
