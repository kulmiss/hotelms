#!/usr/bin/env bash
# HotelMS – install or update on a VPS with almost no downtime, and roll back in one command.
#
#   sudo ./deploy.sh /path/to/hotelms.zip      install a new version (first install too)
#   sudo ./deploy.sh --rollback                go back to the version before
#
# Folders (made by this script):
#   /var/www/releases/<date-time>/   one folder per version (the "hotelms" folder from hotelms.zip)
#   /var/www/hotelms                 a link to the version in use (Nginx and PHP use this path)
#   /var/www/shared/.env             your settings, shared by all versions
#   /var/www/shared/storage/         uploads, logs, sessions – shared by all versions
#
# Option A (Docker): run with HOTELMS_MODE=docker  (artisan runs inside the "app" container).
# Option B (manual): the default.
# The package is made on your laptop with ./deploy/package.sh (see the guide).
set -euo pipefail

MODE="${HOTELMS_MODE:-manual}"                       # manual | docker
BASE="${HOTELMS_BASE:-/var/www}"                     # change only for a test
COMPOSE_DIR="${HOTELMS_COMPOSE_DIR:-/opt/hotelms-docker}"
KEEP="${HOTELMS_KEEP_RELEASES:-5}"                   # how many old versions to keep
WEB_USER="${HOTELMS_WEB_USER:-www-data}"

CURRENT="$BASE/hotelms"
RELEASES="$BASE/releases"
SHARED="$BASE/shared"

say() { printf '\n==> %s\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

artisan() {
    if [ "$MODE" = docker ]; then
        docker compose -f "$COMPOSE_DIR/docker-compose.prod.yml" exec -T -u "$WEB_USER" -w "$CURRENT" app php artisan "$@"
    else
        if [ "$(id -un)" = "$WEB_USER" ]; then php "$CURRENT/artisan" "$@"; else sudo -u "$WEB_USER" php "$CURRENT/artisan" "$@"; fi
    fi
}

reload_php() {
    if [ "$MODE" = docker ]; then
        docker compose -f "$COMPOSE_DIR/docker-compose.prod.yml" restart app scheduler
    elif command -v systemctl >/dev/null && systemctl list-unit-files php8.3-fpm.service >/dev/null 2>&1; then
        systemctl reload php8.3-fpm
    else
        echo "(php8.3-fpm not found – skipped the reload)"
    fi
}

switch_to() { # atomically point $CURRENT at a release folder
    ln -sfn "$1" "$CURRENT.new"
    mv -T "$CURRENT.new" "$CURRENT"
}

if [ "${1:-}" = "--rollback" ]; then
    [ -L "$CURRENT" ] || die "$CURRENT is not a link – nothing to roll back."
    now="$(readlink -f "$CURRENT")"
    prev="$(ls -1d "$RELEASES"/*/ 2>/dev/null | sed 's#/$##' | sort | grep -B1 -x "$now" | head -1)"
    [ -n "$prev" ] && [ "$prev" != "$now" ] || die "No older version found in $RELEASES."
    say "Going back from $(basename "$now") to $(basename "$prev")"
    echo "Database: if the newer version added tables, first run (while still on the newer version):"
    echo "  php artisan migrate:rollback --step=1 --force   (see the guide, Rollback)"
    switch_to "$prev"
    artisan optimize
    reload_php
    artisan up || true
    say "Done. Version in use: $(basename "$(readlink -f "$CURRENT")")"
    exit 0
fi

ZIP="${1:-}"
[ -n "$ZIP" ] && [ -f "$ZIP" ] || die "Usage: sudo $0 /path/to/hotelms.zip   (or --rollback)"
command -v unzip >/dev/null || die "unzip is missing: sudo apt install unzip"

STAMP="$(date +%Y%m%d-%H%M%S)"
NEW="$RELEASES/$STAMP"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

say "1/8 Unpack $ZIP"
unzip -q "$ZIP" -d "$TMP"
[ -f "$TMP/hotelms/artisan" ] || die "This zip has no hotelms/artisan inside – is it the package from deploy/package.sh?"
mkdir -p "$RELEASES" "$SHARED"
mv "$TMP/hotelms" "$NEW"

say "2/8 Shared settings and files"
FIRST=0
if [ ! -d "$SHARED/storage" ]; then
    FIRST=1
    cp -a "$NEW/storage" "$SHARED/storage"           # first install: start from the package's empty folders
fi
mkdir -p "$SHARED/storage/app/public" "$SHARED/storage/app/private" "$SHARED/storage/framework/cache" \
         "$SHARED/storage/framework/sessions" "$SHARED/storage/framework/views" "$SHARED/storage/logs"
rm -rf "$NEW/storage"
ln -s "$SHARED/storage" "$NEW/storage"
if [ ! -f "$SHARED/.env" ]; then
    cp "$NEW/.env.production.example" "$SHARED/.env"
    chown "$WEB_USER":"$WEB_USER" "$SHARED/.env"; chmod 640 "$SHARED/.env"
    echo
    echo "First install: $SHARED/.env was created from .env.production.example."
    echo "Fill it in (database, mail, domain – see the guide), then run this script again with the same zip."
    rm -rf "$NEW"
    exit 1
fi
ln -sfn "$SHARED/.env" "$NEW/.env"
chown -R "$WEB_USER":"$WEB_USER" "$NEW" "$SHARED/storage"
chmod -R ug+rwX "$SHARED/storage"

if [ -L "$CURRENT" ] || [ -d "$CURRENT" ]; then
    say "3/8 Backup first (php artisan hotelms:backup)"
    artisan hotelms:backup || { rm -rf "$NEW"; die "The backup failed – nothing was changed. Fix the backup first."; }
    say "4/8 Maintenance mode on"
    artisan down || true
else
    say "3/8 + 4/8 First install: no backup and no maintenance mode needed"
fi
if [ -d "$CURRENT" ] && [ ! -L "$CURRENT" ]; then
    die "$CURRENT is a normal folder (an older install). Move it away first: sudo mv $CURRENT $CURRENT.old"
fi

say "5/8 Switch to the new version ($STAMP)"
switch_to "$NEW"

say "6/8 Database changes (php artisan migrate --force)"
# APP_KEY is made once, at the first install, and never changed again (guest IDs, two-step and backups need it).
grep -q '^APP_KEY=base64:' "$SHARED/.env" || artisan key:generate --force
artisan migrate --force
if [ "$FIRST" = 1 ]; then
    artisan db:seed --force                           # creates the Super Admin (and the demo hotel)
fi
artisan storage:link || true

say "7/8 Speed caches (php artisan optimize) and PHP reload"
artisan optimize
reload_php

say "8/8 Maintenance mode off"
artisan up || true

# Keep only the newest $KEEP versions.
ls -1d "$RELEASES"/*/ | sed 's#/$##' | sort | head -n -"$KEEP" | while read -r old; do
    [ "$old" = "$(readlink -f "$CURRENT")" ] || rm -rf "$old"
done

say "Done. Version in use: $STAMP. Open https://<YOUR_DOMAIN>/ and check Super Admin → System."
