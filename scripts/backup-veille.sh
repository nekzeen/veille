#!/bin/bash
# backup-veille.sh — M12.8 : sauvegarde quotidienne + rotation 7 j.
# Politique : répertoire dédié veille-auto/ — SEUL espace géré.
# Les sauvegardes manuelles (backups/veille/*) ne sont JAMAIS touchées.
set -o pipefail
set -u

BASE="/var/www/clients/client2/web18/private"
APP="$BASE/veille"
DEST="$BASE/backups/veille-auto"
LOG="$BASE/backups/backup.log"
RETENTION_DAYS=7
STAMP="$(date +%Y%m%d-%H%M%S)"
DIR="$DEST/$STAMP"

log() { echo "$(date +'%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }

mkdir -p "$DIR" || { log "ERROR mkdir $DIR"; exit 1; }

# Identifiants lus depuis .env (MYSQL_PWD = jamais en argument).
set -a; source <(grep -E '^DB_(HOST|PORT|USERNAME|PASSWORD|DATABASE)=' "$APP/.env") ; set +a
export MYSQL_PWD="$DB_PASSWORD"

if mysqldump --single-transaction --quick -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" "$DB_DATABASE" | gzip -c > "$DIR/veille_db.sql.gz"; then
    if gunzip -t "$DIR/veille_db.sql.gz" 2>/dev/null && [ -s "$DIR/veille_db.sql.gz" ]; then
        log "OK dump $DIR/veille_db.sql.gz ($(du -h "$DIR/veille_db.sql.gz" | cut -f1))"
        sha256sum "$DIR/veille_db.sql.gz" > "$DIR/SHA256SUM.txt"
        cp -f "$APP/bootstrap/cache/routes-v7.php" "$DIR/routes-v7.php.bak" 2>/dev/null || true
        git -C "$APP" rev-parse HEAD > "$DIR/git-head.txt" 2>/dev/null || true
    else
        log "ERROR integrity $DIR — dump invalide, conservé pour diagnostic"
        exit 1
    fi
else
    log "ERROR mysqldump $DIR"
    exit 1
fi

# Rotation : supprime UNIQUEMENT dans veille-auto les entrées
# au format horodaté plus vieilles que RETENTION_DAYS — jamais ailleurs.
deleted=0
while IFS= read -r d; do
    name="$(basename "$d")"
    # Refuse tout nom non conforme — sécurité anti-fausse-identification.
    [[ "$name" =~ ^[0-9]{8}-[0-9]{6}$ ]] || { log "SKIP non-conforme $name"; continue; }
    if find "$d" -maxdepth 0 -mtime +"$RETENTION_DAYS" | grep -q .; then
        rm -rf -- "$d" && log "DELETE $name (> ${RETENTION_DAYS}j)" && deleted=$((deleted+1))
    fi
done < <(find "$DEST" -mindepth 1 -maxdepth 1 -type d)

log "DONE création=$STAMP rotation=$deleted"
exit 0
