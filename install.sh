#!/usr/bin/env bash
# =====================================================================
# Zorvex | Network — one-line installer
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/USER/REPO/main/install.sh)
#   # or:  bash install.sh
#
# Installs Docker + Compose (when missing), configures .env, builds and
# starts the full stack, then registers the Telegram webhook.
# =====================================================================

set -euo pipefail

# ---------- Constants / config ----------
REPO_URL="https://github.com/T3chHash/Zorvex.git"
APP_DIR="${ZORVEX_DIR:-$HOME/Zorvex}"
HTTP_PORT="${HTTP_PORT:-8080}"
BOT_TOKEN_RAW="${TELEGRAM_BOT_TOKEN:-}"
ADMIN_IDS="${TELEGRAM_ADMIN_IDS:-}"
APP_PUBLIC_URL="${APP_URL:-}"

BOLD="\e[1m"; DIM="\e[2m"; GREEN="\e[32m"; CYAN="\e[36m"; YELLOW="\e[33m"; RED="\e[31m"; RESET="\e[0m"

log()  { echo -e "${GREEN}[✓]${RESET} $*"; }
info() { echo -e "${CYAN}[i]${RESET} $*"; }
warn() { echo -e "${YELLOW}[!]${RESET} $*"; }
fail() { echo -e "${RED}[✗]${RESET} $*" >&2; exit 1; }

logo() {
cat <<'EOF'

   ███████╗ ██████╗ ██████╗ ██╗   ██╗███████╗██╗  ██╗
   ╚══███╔╝██╔═══██╗██╔══██╗██║   ██║██╔════╝╚██╗██╔╝
     ███╔╝ ██║   ██║██████╔╝██║   ██║█████╗   ╚███╔╝
    ███╔╝  ██║   ██║██╔══██╗╚██╗ ██╔╝██╔══╝   ██╔██╗
   ███████╗╚██████╔╝██║  ██║ ╚████╔╝ ███████╗██╔╝ ██╗
   ╚══════╝ ╚═════╝ ╚═╝  ╚═╝  ╚═══╝  ╚══════╝╚═╝  ╚═╝
        ███╗   ██╗███████╗████████╗██╗    ██╗ ██████╗ ██████╗ ███╗   ██╗
        ████╗  ██║██╔════╝╚══██╔══╝██║    ██║██╔═══██╗██╔══██╗████╗  ██║
        ██╔██╗ ██║█████╗     ██║   ██║ █╗ ██║██║   ██║██████╔╝██╔██╗ ██║
        ██║╚██╗██║██╔══╝     ██║   ██║███╗██║██║   ██║██╔══██╗██║╚██╗██║
        ██║ ╚████║███████╗   ██║   ╚███╔███╔╝╚██████╔╝██║  ██║██║ ╚████║
        ╚═╝  ╚═══╝╚══════╝   ╚═╝    ╚══╝╚══╝  ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═══╝

EOF
}

# ---------- Preflight helpers ----------
command_exists() { command -v "$1" >/dev/null 2>&1; }

have_docker() {
  command_exists docker && docker compose version >/dev/null 2>&1 || command_exists docker-compose
}

install_docker() {
  info "Docker not found — installing via official script..."
  if command_exists curl; then
    curl -fsSL https://get.docker.com | sh
  elif command_exists wget; then
    wget -qO- https://get.docker.com | sh
  else
    fail "Need curl or wget to fetch the Docker installer."
  fi
  log "Docker installed."
  if [ "$(id -u)" -ne 0 ]; then
    usermod -aG docker "$(whoami)" 2>/dev/null || true
    warn "Run 'newgrp docker' or re-login to use Docker without sudo."
  fi
}

# ---------- Step 1: logo ----------
logo

# ---------- Step 2: git clone ----------
if [ -d "${APP_DIR}/.git" ]; then
  info "Zorvex already cloned at ${APP_DIR} — pulling latest..."
  git -C "${APP_DIR}" pull --ff-only || warn "Could not pull (branch is dirty or offline). Keeping local state."
else
  mkdir -p "${APP_DIR}"
  git clone --depth 1 "${REPO_URL}" "${APP_DIR}"
  log "Repository cloned into ${APP_DIR}."
fi

cd "${APP_DIR}"

# ---------- Step 3: docker ----------
if ! have_docker; then
  install_docker
fi
docker --version
docker compose version 2>/dev/null || docker-compose --version

# ---------- Step 4: .env ----------
if [ ! -f .env ]; then
  cp .env.example .env
  log ".env created from template."
else
  info ".env already exists — leaving it untouched."
fi

# Auto-fill non-default values when provided through the environment.
if [ -n "${BOT_TOKEN_RAW}" ]; then
  sed -i "s|^TELEGRAM_BOT_TOKEN=.*|TELEGRAM_BOT_TOKEN=${BOT_TOKEN_RAW}|" .env
  log "TELEGRAM_BOT_TOKEN set."
fi
if [ -n "${ADMIN_IDS}" ]; then
  sed -i "s|^TELEGRAM_ADMIN_IDS=.*|TELEGRAM_ADMIN_IDS=${ADMIN_IDS}|" .env
  log "TELEGRAM_ADMIN_IDS set."
fi
if [ -n "${APP_PUBLIC_URL}" ]; then
  sed -i "s|^APP_URL=.*|APP_URL=${APP_PUBLIC_URL}|" .env
  log "APP_URL set to ${APP_PUBLIC_URL}."
fi

# Generate a strong APP_KEY when placeholder remains.
if grep -q "CHANGE_ME" .env; then
  NEW_KEY="$(openssl rand -hex 32 2>/dev/null || head -c 64 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  sed -i "s|^APP_KEY=.*|APP_KEY=${NEW_KEY}|" .env
  log "APP_KEY generated."
fi

# ---------- Step 5: build + up ----------
export COMPOSE_PROJECT_NAME="zorvex"
log "Building & starting the stack..."
docker compose up --build -d

# ---------- Step 6: wait for health ----------
info "Waiting for services to become healthy..."
attempt=0
max=60
until [ "${attempt}" -ge "${max}" ]; do
  health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}running{{end}}' zorvex-mysql 2>/dev/null || echo waiting)"
  if curl -fsS "http://127.0.0.1:${HTTP_PORT}/health" >/dev/null 2>&1; then
    log "Stack is up and responding on port ${HTTP_PORT}."
    break
  fi
  attempt=$((attempt + 1))
  sleep 3
done

if ! curl -fsS "http://127.0.0.1:${HTTP_PORT}/health" >/dev/null 2>&1; then
  warn "The stack did not report healthy yet — check: docker compose ps"
  warn "Logs: docker compose logs --tail=100"
  exit 0
fi

# ---------- Step 7: register webhook ----------
TOKEN_IN_ENV="$(grep -E '^TELEGRAM_BOT_TOKEN=' .env | cut -d= -f2- | tr -d '"')"
URL_IN_ENV="$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '"')"

if [ -n "${TOKEN_IN_ENV}" ] && [ -n "${URL_IN_ENV}" ]; then
  info "Registering Telegram webhook → ${URL_IN_ENV}/webhook"
  RESULT="$(curl -fsS "https://api.telegram.org/bot${TOKEN_IN_ENV}/setWebhook?url=${URL_IN_ENV}/webhook" || true)"
  if echo "${RESULT}" | grep -q '"ok":true'; then
    log "Webhook registered successfully."
  else
    warn "Webhook registration failed/unknown: ${RESULT:-unreachable}"
    warn "Register manually: curl \"https://api.telegram.org/bot{TOKEN}/setWebhook?url=${URL_IN_ENV}/webhook\""
  fi
else
  warn "TELEGRAM_BOT_TOKEN or APP_URL not set — register the webhook manually after configuring .env"
fi

# ---------- Summary ----------
cat <<EOF

${BOLD}─────────────────────────────────────────────${RESET}
${GREEN}✅ Zorvex | Network is up!${RESET}
${BOLD}─────────────────────────────────────────────${RESET}

  🌐 App        : http://127.0.0.1:${HTTP_PORT}
  📱 Mini-app   : ${URL_IN_ENV:-<set APP_URL>}/miniapp
  🏛 Admin      : ${URL_IN_ENV:-<set APP_URL>}/admin.php
  🧾 Logs       : docker compose logs -f php-fpm

  Useful commands:
    docker compose ps
    docker compose logs -f
    docker compose down && docker compose up -d     (restart)

${DIM}Add your VPN panels from the admin panel, then create products.${RESET}
EOF

exit 0