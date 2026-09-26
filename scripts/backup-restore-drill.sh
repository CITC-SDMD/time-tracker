#!/usr/bin/env bash
# Backup and restore drill (docs/DEVELOPMENT_PLAN.md test 7.6, docs/PILOT_CHECKLIST.md).
#
# Takes a backup of the real database, restores it into a SEPARATE, disposable database, and compares the number of
# rows in every table. A backup that has never been restored is only a hope: run this on the server once before the
# first pilot and again after every big change to the database.
#
# It never writes to the real database. The disposable database must already exist (create it once with a user that
# may) and everything in it is REPLACED, so never point DRILL_DB at data you want to keep.
#
#   DB_NAME=tracker_prod DB_USER=tracker DRILL_DB=tracker_restore_drill ./scripts/backup-restore-drill.sh
#   (the password is read from MYSQL_PWD, or asked for by mysql)
#
# Optional: DB_HOST (127.0.0.1), DB_PORT (3306), BIN (folder of mysql and mysqldump, when they are not on the PATH),
# BACKUP_DIR (where the backup file is kept, default the current folder).
set -euo pipefail

: "${DB_NAME:?set DB_NAME to the real database}"
: "${DB_USER:?set DB_USER}"
: "${DRILL_DB:?set DRILL_DB to a disposable database that already exists}"
if [ "$DB_NAME" = "$DRILL_DB" ]; then
  echo "DRILL_DB must not be the real database." >&2
  exit 2
fi

HOST="${DB_HOST:-127.0.0.1}"
PORT="${DB_PORT:-3306}"
BIN="${BIN:-}"
[ -n "$BIN" ] && BIN="$BIN/"
BACKUP_DIR="${BACKUP_DIR:-.}"
FILE="$BACKUP_DIR/drill-$(date +%F-%H%M%S).sql.gz"
MYSQL=("${BIN}mysql" -h "$HOST" -P "$PORT" -u "$DB_USER")

echo "1/4  Backing up $DB_NAME to $FILE"
"${BIN}mysqldump" -h "$HOST" -P "$PORT" -u "$DB_USER" --single-transaction --routines --no-tablespaces "$DB_NAME" | gzip > "$FILE"
[ -s "$FILE" ] || { echo "The backup file is empty." >&2; exit 1; }
echo "     $(wc -c < "$FILE") bytes"

echo "2/4  Restoring into $DRILL_DB (its old content is replaced)"
"${MYSQL[@]}" -e "DROP DATABASE \`$DRILL_DB\`; CREATE DATABASE \`$DRILL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null \
  || echo "     (could not recreate the database, restoring over what is there: tables are dropped by the dump itself)"
gunzip < "$FILE" | "${MYSQL[@]}" "$DRILL_DB"

echo "3/4  Comparing the number of rows in every table"
tables=$("${MYSQL[@]}" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_type='BASE TABLE' ORDER BY table_name;" | tr -d '\r')
bad=0
for t in $tables; do
  a=$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.\`$t\`;" | tr -d '\r')
  b=$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM \`$DRILL_DB\`.\`$t\`;" 2>/dev/null | tr -d '\r' || echo "missing")
  if [ "$a" = "$b" ]; then
    printf '     ok    %-28s %s\n' "$t" "$a"
  else
    printf '     DIFF  %-28s real=%s restored=%s\n' "$t" "$a" "$b"
    bad=1
  fi
done

echo "4/4  Result"
if [ "$bad" = 0 ]; then
  echo "     The backup restores completely. Keep $FILE or delete it: it holds real data."
else
  echo "     The restored copy differs from the real database (rows written while the drill ran can explain small differences on a live server; anything else is a problem)." >&2
  exit 1
fi
