#!/usr/bin/env bash
# HotelMS – copy the backups to a second place, off this server (deploy-guide/vps.md, "Backups").
# The app already makes the backups itself (php artisan hotelms:backup: full every night, small every hour,
# uploaded files every night into <BACKUP_PATH>/files). This script only copies that folder somewhere else.
#
# Needs rclone ("sudo apt install rclone") and a remote set up once with "rclone config" (name: hotelms-offsite).
# Data law: guest data must stay in Ethiopia – choose a storage place in Ethiopia (CHECK with your provider).
# Run every night from root's crontab, after the app's nightly backup (01:15 UTC) – for example at 02:00 UTC:
#   0 2 * * * /usr/local/bin/hotelms-offsite-backup.sh >> /var/log/hotelms-offsite.log 2>&1
set -euo pipefail

BACKUP_PATH="${BACKUP_PATH:-/var/backups/hotelms}"          # the same as BACKUP_PATH in .env
REMOTE="${HOTELMS_REMOTE:-hotelms-offsite:hotelms-backups}" # <rclone remote name>:<folder or bucket>

[ -d "$BACKUP_PATH" ] || { echo "No backup folder $BACKUP_PATH"; exit 1; }
echo "$(date -u +%FT%TZ) copying $BACKUP_PATH to $REMOTE"
# "copy" never deletes on the other side; old files are removed there by the provider's own rules (or by hand).
rclone copy "$BACKUP_PATH" "$REMOTE" --transfers 2 --checkers 4
echo "$(date -u +%FT%TZ) done"
