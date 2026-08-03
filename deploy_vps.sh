#!/usr/bin/env bash
#
# Deploy al VPS de producción (pompeisanitarioscontable.cloud).
#
# A diferencia de deploy.py (hosting compartido, sin git ni node, sube archivo
# por archivo por SFTP), el VPS SÍ tiene git, composer, node y systemd — así
# que el deploy es: git pull + reinstalar sólo lo que cambió + cachear.
#
# Requiere que el commit a deployar ya esté pusheado a origin/main (el VPS
# clona por HTTPS/SSH desde GitHub, no recibe archivos locales sueltos).
#
# Uso:
#   ./deploy_vps.sh              Deploy completo (git pull + deps condicionales + migrate + cache)
#   ./deploy_vps.sh --check      Sólo smoke test (HTTP de la home)
#   ./deploy_vps.sh --logs       Tail de storage/logs/laravel.log
#
set -euo pipefail

VPS_HOST="46.202.146.102"
VPS_USER="root"
VPS_KEY="$HOME/.ssh/contagram_vps"
REMOTE_BASE="/var/www/contagram"
DOMINIO="https://pompeisanitarioscontable.cloud"

ssh_vps() {
    ssh -i "$VPS_KEY" -o ConnectTimeout=15 "${VPS_USER}@${VPS_HOST}" "$@"
}

if [[ "${1:-}" == "--check" ]]; then
    echo "Smoke test: ${DOMINIO}/"
    curl -s -o /dev/null -w 'HTTP %{http_code}\n' "${DOMINIO}/"
    exit 0
fi

if [[ "${1:-}" == "--logs" ]]; then
    ssh_vps "tail -100 ${REMOTE_BASE}/storage/logs/laravel.log"
    exit 0
fi

echo "== git pull =="
BEFORE=$(ssh_vps "cd ${REMOTE_BASE} && git rev-parse HEAD")
ssh_vps "cd ${REMOTE_BASE} && GIT_SSH_COMMAND='ssh -i /root/.ssh/pompei_deploy_key -o StrictHostKeyChecking=accept-new' git pull origin main"
AFTER=$(ssh_vps "cd ${REMOTE_BASE} && git rev-parse HEAD")

if [[ "$BEFORE" == "$AFTER" ]]; then
    echo "Sin cambios nuevos (ya estaba en $AFTER). Nada para deployar."
    exit 0
fi

echo "== Cambios: ${BEFORE:0:7} -> ${AFTER:0:7} =="
CAMBIOS=$(ssh_vps "cd ${REMOTE_BASE} && git diff --name-only ${BEFORE} ${AFTER}")
echo "$CAMBIOS"

if echo "$CAMBIOS" | grep -q '^composer\.\(json\|lock\)$'; then
    echo "== composer.json/lock cambió: composer install =="
    ssh_vps "cd ${REMOTE_BASE} && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader"
fi

if echo "$CAMBIOS" | grep -qE '^(package(-lock)?\.json|resources/(js|css)/|vite\.config\.js)'; then
    echo "== JS/CSS cambió: npm install + build =="
    ssh_vps "cd ${REMOTE_BASE} && npm install && npm run build"
fi

if echo "$CAMBIOS" | grep -q '^database/migrations/'; then
    echo "== Migraciones nuevas: migrate --force =="
    ssh_vps "cd ${REMOTE_BASE} && php artisan migrate --force"
fi

echo "== Permisos =="
ssh_vps "chown -R www-data:www-data ${REMOTE_BASE} && chmod -R 775 ${REMOTE_BASE}/storage ${REMOTE_BASE}/bootstrap/cache"

echo "== Cache =="
ssh_vps "cd ${REMOTE_BASE} && php artisan config:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache"

echo "== Reiniciar queue worker (código cambió, el worker tiene el viejo en memoria) =="
ssh_vps "systemctl restart contagram-queue"

echo "== Smoke test =="
curl -s -o /dev/null -w 'HTTP %{http_code}\n' "${DOMINIO}/"

echo "Deploy OK: ${BEFORE:0:7} -> ${AFTER:0:7}"
