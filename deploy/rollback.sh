#!/usr/bin/env bash
# Volta o CÓDIGO para uma versão anterior. Não desfaz migrations nem apaga pagamentos recebidos:
# reversão de banco exige procedimento próprio (restaurar backup em homologação, conferir e só então aplicar).
set -euo pipefail
APP=/var/www/planossf
TARGET=${1:?Informe o commit de destino}
cd "$APP"
sudo -u deploy git reset --hard "$TARGET"
sudo -u deploy -H composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --quiet
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover >/dev/null
sudo -u deploy -H npm ci --no-audit --no-fund --silent && sudo -u deploy -H npm run build --silent
php artisan optimize:clear >/dev/null
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:deploy storage bootstrap/cache && find storage bootstrap/cache -type d -exec chmod g+rwxs {} +
php artisan queue:restart && systemctl restart planossf-queue
echo "Código revertido para $TARGET"
