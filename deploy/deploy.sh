#!/usr/bin/env bash
# Implantação do PlanosSF na VPS (seção 25). Executar como root:  bash deploy/deploy.sh
# Pré-requisitos: backup recuperável do banco e dos documentos antes de migrations.
set -euo pipefail

APP=/var/www/planossf
cd "$APP"

echo "==> Backup do banco antes da migração"
mkdir -p /var/backups/planossf
mysqldump --no-defaults -uroot --single-transaction --routines planossf | gzip > "/var/backups/planossf/pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz"

PREVIOUS=$(sudo -u deploy git rev-parse HEAD)
echo "==> Versão anterior: $PREVIOUS (use 'bash deploy/rollback.sh $PREVIOUS' para voltar)"

echo "==> Atualizando código"
sudo -u deploy git fetch --quiet origin
sudo -u deploy git reset --hard origin/main

echo "==> Dependências de produção"
sudo -u deploy -H composer install --no-dev --optimize-autoloader --no-interaction --quiet
sudo -u deploy -H npm ci --no-audit --no-fund --silent
sudo -u deploy -H npm run build --silent

echo "==> Banco e caches"
php artisan down --retry=15 || true
# Sem cache de config, as variáveis do ambiente valem mais que o .env: a migration roda com o dono do
# banco (arquivo só do root) e a aplicação, com o usuário do .env, que não altera tabelas.
php artisan optimize:clear >/dev/null
MIGRATOR_ENV=/root/.planossf-migrator.env
if [ -f "$MIGRATOR_ENV" ]; then
  (set -a; . "$MIGRATOR_ENV"; set +a; php artisan migrate --force)
else
  echo "AVISO: $MIGRATOR_ENV não existe; migration com o usuário do .env." >&2
  php artisan migrate --force
fi
bash deploy/app-grants.sh
php artisan db:seed --class=AccessSeeder --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
chown -R www-data:www-data storage bootstrap/cache
php artisan up

echo "==> Reiniciando workers"
php artisan queue:restart
systemctl restart planossf-queue

echo "==> Verificação"
curl -fsS -o /dev/null -w "HTTP %{http_code}\n" "$(grep '^APP_URL=' .env | cut -d= -f2)/up"
systemctl is-active planossf-queue
