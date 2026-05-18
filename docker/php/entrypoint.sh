#!/bin/bash
set -e

# ─────────────────────────────────────────────────────────
# HitechFibre Docker Entrypoint
# Runs for ALL containers (web, worker, cron).
# Detects role via CONTAINER_ROLE env var.
# ─────────────────────────────────────────────────────────

APP_DIR="/var/www/html"
ROLE="${CONTAINER_ROLE:-web}"

echo "[entrypoint] Starting HitechFibre container (role: ${ROLE})"

# ── 1. Create runtime directories ────────────────────────
mkdir -p "${APP_DIR}/logs" \
         "${APP_DIR}/state/cache" \
         "${APP_DIR}/state/sessions"
chown -R www-data:www-data "${APP_DIR}/logs" "${APP_DIR}/state" 2>/dev/null || true
chmod -R 755 "${APP_DIR}/logs" "${APP_DIR}/state"

# ── 2. Generate settings.json from environment variables ─
# This means you NEVER commit secrets — all config comes
# from Portainer's "Environment variables" panel.
cat > "${APP_DIR}/config/settings.json" <<EOF
{
  "app": {
    "env": "${APP_ENV:-production}",
    "debug": ${APP_DEBUG:-false},
    "webhook_secret": "${APP_WEBHOOK_SECRET:-}",
    "webhook_url": "http://localhost/webhook.php"
  },
  "database": {
    "driver": "${DB_DRIVER:-mysql}",
    "host": "${DB_HOST:-db}",
    "port": ${DB_PORT:-3306},
    "name": "${DB_NAME:-hitechfibre}",
    "user": "${DB_USER:-hitechfibre}",
    "pass": "${DB_PASS:-}",
    "path": "${DB_PATH:-/var/www/html/state/db.sqlite}"
  },
  "redis": {
    "host": "${REDIS_HOST:-redis}",
    "port": ${REDIS_PORT:-6379},
    "password": "${REDIS_PASSWORD:-}",
    "db": ${REDIS_DB:-0}
  },
  "splynx": {
    "url": "${SPLYNX_URL:-}",
    "api_key": "${SPLYNX_API_KEY:-}",
    "api_secret": "${SPLYNX_API_SECRET:-}"
  },
  "respond_io": {
    "api_key": "${RESPONDIO_API_KEY:-}",
    "inbox_id": "${RESPONDIO_INBOX_ID:-}"
  },
  "openai": {
    "enabled": ${OPENAI_ENABLED:-false},
    "api_key": "${OPENAI_API_KEY:-}",
    "model": "${OPENAI_MODEL:-gpt-4o-mini}",
    "max_tokens": 300,
    "temperature": 0.7
  },
  "teams": {
    "tech_support": "${RESPONDIO_TEAM_TECH:-1}",
    "accounts": "${RESPONDIO_TEAM_ACCOUNTS:-2}",
    "sales": "${RESPONDIO_TEAM_SALES:-3}"
  },
  "bot": {
    "anti_spam_delay": 8,
    "after_hours_enabled": true,
    "session_timeout_hours": 24
  },
  "business_hours": {
    "timezone": "${BH_TIMEZONE:-Africa/Johannesburg}",
    "mon_fri_start": "${BH_MON_FRI_START:-08:00}",
    "mon_fri_end": "${BH_MON_FRI_END:-17:00}",
    "sat_start": "${BH_SAT_START:-08:00}",
    "sat_end": "${BH_SAT_END:-13:00}"
  },
  "admin": {
    "username": "${ADMIN_USERNAME:-admin}",
    "password": "${ADMIN_PASSWORD:-}",
    "api_token": "${ADMIN_API_TOKEN:-}",
    "secret_path": "${ADMIN_SECRET_PATH:-hitechfibre_admin}"
  }
}
EOF

echo "[entrypoint] settings.json generated from environment"

# ── 3. Run migrations (web and worker only, not cron) ────
if [[ "$ROLE" == "web" || "$ROLE" == "worker" ]]; then
  echo "[entrypoint] Waiting for database..."
  
  MAX_TRIES=30
  COUNT=0
  until php -r "
    \$driver = getenv('DB_DRIVER') ?: 'mysql';
    if (\$driver === 'sqlite') { echo 'ready'; exit(0); }
    \$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: 3306) . ';dbname=' . (getenv('DB_NAME') ?: 'hitechfibre');
    try {
      new PDO(\$dsn, getenv('DB_USER') ?: 'hitechfibre', getenv('DB_PASS') ?: '');
      echo 'ready';
    } catch (Exception \$e) {
      echo 'waiting';
    }
  " 2>/dev/null | grep -q 'ready'; do
    COUNT=$((COUNT + 1))
    if [ $COUNT -ge $MAX_TRIES ]; then
      echo "[entrypoint] Database not ready after ${MAX_TRIES}s — continuing anyway"
      break
    fi
    echo "[entrypoint] Database not ready yet (attempt ${COUNT}/${MAX_TRIES})..."
    sleep 2
  done

  echo "[entrypoint] Running migrations..."
  php "${APP_DIR}/artisan" migrate 2>&1 || echo "[entrypoint] Migration warning (may already be applied)"
fi

# ── 4. Fix permissions on generated config ───────────────
chown www-data:www-data "${APP_DIR}/config/settings.json" 2>/dev/null || true

echo "[entrypoint] Startup complete — launching role: ${ROLE}"

# ── 5. Start the appropriate process ─────────────────────
case "$ROLE" in
  web)
    exec apache2-foreground
    ;;
  worker)
    exec php "${APP_DIR}/artisan" queue:work
    ;;
  cron)
    crontab "${APP_DIR}/docker/crontab"
    exec cron -f
    ;;
  *)
    echo "[entrypoint] Unknown CONTAINER_ROLE '${ROLE}'. Valid: web, worker, cron"
    exit 1
    ;;
esac
