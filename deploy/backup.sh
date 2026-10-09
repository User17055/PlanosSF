#!/usr/bin/env bash
# Backup diário criptografado do banco e dos documentos (seção 25). Agendar no cron do root:
#   30 2 * * * /var/www/planossf/deploy/backup.sh >> /var/log/planossf-backup.log 2>&1
# Requer /root/.planossf-backup-pass (chmod 600) e um destino EXTERNO à VPS em BACKUP_REMOTE
# (ex.: rclone remote). Snapshot da mesma VPS não é plano de backup suficiente.
set -euo pipefail
APP=/var/www/planossf
DEST=/var/backups/planossf
STAMP=$(date +%Y%m%d-%H%M%S)
PASS_FILE=/root/.planossf-backup-pass
mkdir -p "$DEST"

mysqldump --no-defaults -uroot --single-transaction --routines planossf | gzip \
  | openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:$PASS_FILE" > "$DEST/db-$STAMP.sql.gz.enc"
tar -C "$APP/storage/app/private" -czf - documents \
  | openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:$PASS_FILE" > "$DEST/docs-$STAMP.tar.gz.enc"

find "$DEST" -name '*.enc' -mtime +30 -delete

if [ -n "${BACKUP_REMOTE:-}" ]; then
  rclone copy "$DEST" "$BACKUP_REMOTE" --include "*-$STAMP.*"
else
  echo "AVISO: BACKUP_REMOTE não configurado — backup ficou apenas na VPS." >&2
fi
echo "backup $STAMP ok"
