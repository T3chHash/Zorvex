#!/bin/sh
# =====================================================================
# Zorvex | Network — container entrypoint
#
#  1. Wait for MySQL to accept connections.
#  2. Apply migrations when EXECUTE_MIGRATIONS=1.
#  3. Install Composer deps if vendor/ is missing (dev convenience).
#  4. Exec the requested command (php-fpm by default).
# =====================================================================
set -eu

: "${DB_HOST:=mysql}"
: "${DB_PORT:=3306}"
: "${DB_DATABASE:=zorvex}"
: "${DB_USERNAME:=zorvex}"
: "${DB_PASSWORD:=zorvex_secret}"
: "${EXECUTE_MIGRATIONS:=1}"
: "${PHP_FPM_CONF:=-F}"

log() {
  echo "[zorvex:entrypoint] $*"
}

# --- Wait for MySQL ---
if [ "${DB_HOST}" != "sqlite" ] && [ "${DB_HOST}" != "" ]; then
  log "Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."
  attempt=0
  max_attempts=60
  until mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" \
        -u"${DB_USERNAME}" -p"${DB_PASSWORD}" --silent >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "${attempt}" -ge "${max_attempts}" ]; then
      log "MySQL did not become ready; giving up."
      exit 1
    fi
    sleep 2
  done
  log "MySQL is ready."
fi

# --- Apply migrations ---
if [ "${EXECUTE_MIGRATIONS}" = "1" ]; then
  log "Applying database migrations..."
  if [ -d /var/www/html/migrations ]; then
    for file in /var/www/html/migrations/*.sql; do
      case "${file}" in
        *.sql)
          log "Applying $(basename "${file}")..."
          mysql -h"${DB_HOST}" -P"${DB_PORT}" \
                -u"${DB_USERNAME}" -p"${DB_PASSWORD}" \
                "${DB_DATABASE}" < "${file}" \
            || log "Migration $(basename "${file}") failed (may already be applied)."
          ;;
      esac
    done
    log "Migrations complete."
  fi
fi

# --- Install Composer deps when missing ---
if [ ! -f /var/www/html/vendor/autoload.php ]; then
  log "vendor/ missing — running composer install (dev mode)..."
  composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    || log "Composer install failed; continuing anyway."
  chown -R www-data:www-data /var/www/html/vendor || true
fi

log "Starting: $*"
exec "$@"