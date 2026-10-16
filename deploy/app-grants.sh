#!/usr/bin/env bash
# Permissões do usuário da aplicação (o do .env). O dono do banco (planossf) fica só para as migrations.
# Rodar como root depois de cada migrate: o MySQL não tem "tudo menos uma tabela", então tabela nova
# precisa de grant próprio. Idempotente.
#   - tabelas comuns: SELECT, INSERT, UPDATE, DELETE;
#   - livro de benefícios, auditoria e evidências de aceite: só SELECT e INSERT (o model já proíbe
#     alterar e excluir; aqui o banco também proíbe);
#   - LOCK TABLES no banco: o MySQL 8 exige UPDATE, DELETE ou LOCK TABLES para SELECT ... FOR UPDATE,
#     e o estorno trava o lançamento original assim.
# Não revoga nada: se uma tabela passar a ser só inserção, revogue UPDATE/DELETE dela à mão.
set -euo pipefail
DB=${PLANOS_DB:-planossf}
APP_USER=${PLANOS_APP_USER:-planossf_app}
APP_HOST=${PLANOS_APP_HOST:-localhost}
MYSQL=${MYSQL:-mysql --no-defaults -uroot}
APPEND_ONLY=" benefit_ledger audit_logs acceptance_evidence "

run() { $MYSQL -N -e "$1"; }

if [ -z "$(run "SELECT 1 FROM mysql.user WHERE user = '$APP_USER' AND host = '$APP_HOST'")" ]; then
  echo "app-grants: usuário '$APP_USER'@'$APP_HOST' não existe; nada a fazer." >&2
  exit 0
fi

SQL="GRANT LOCK TABLES ON \`$DB\`.* TO '$APP_USER'@'$APP_HOST';"
for t in $(run "SELECT table_name FROM information_schema.tables WHERE table_schema = '$DB' AND table_type = 'BASE TABLE' ORDER BY table_name"); do
  case "$APPEND_ONLY" in
    *" $t "*) SQL+="GRANT SELECT, INSERT ON \`$DB\`.\`$t\` TO '$APP_USER'@'$APP_HOST';" ;;
    *) SQL+="GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB\`.\`$t\` TO '$APP_USER'@'$APP_HOST';" ;;
  esac
done
run "$SQL"
echo "app-grants: permissões de '$APP_USER'@'$APP_HOST' em $DB atualizadas."
