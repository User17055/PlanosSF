# PlanosSF — Planos de saúde veterinária São Francisco

Software web para comercialização e gestão dos planos: apresentação do plano, cadastro de tutor e pet,
contrato, confirmação do pagamento, vigência, carências, autorização de atendimentos e encerramento,
para toda a rede de franquias. Implementa a *Especificação de Software — Planos São Francisco (PHP/VPS)*.

**Stack:** PHP 8.3+ · Laravel 13 · Blade + Bootstrap 5 · MySQL 8 (InnoDB) · Redis (fila/cache) ·
Nginx + PHP-FPM · systemd + cron · dompdf.

## Mapa dos requisitos

| Requisito | Onde está |
|---|---|
| RF-01 tutores e pets, histórico | `CustomerController`, `PetController`, `audit_logs` |
| RF-02 catálogo versionado | `Services/Catalog/PlanCatalogService` — versão publicada é imutável |
| RF-03 propostas nos 3 canais | `Services/Proposals/ProposalService`, `Public/SiteController`, `Public/PublicProposalController` |
| RF-04 aceite, tablet, papel, PDF | `Services/Contracts/ContractService`, `ContractPdfService`, `TabletController`, `DocumentController` |
| RF-05/06 cobrança e conciliação | `Services/Billing/*` (Vindi e simulador), `webhook_inbox`, `planos:reconcile` |
| RF-07 vigência e elegibilidade | `Services/Subscriptions/ActivationService` (serviço único de ativação) |
| RF-08/09 carência, reserva, consumo, estorno | `Services/Benefits/*` (bloqueio cliente → assinatura → período) |
| RF-10 SimplesVet/TudoPraPet | `Services/Integrations/*` — tarefas manuais auditadas até haver API confirmada |
| RF-11 painel e portal | `resources/views`, `Portal/*` |
| RF-12 permissões, auditoria, recuperação | `Services/Access/*`, `AuditLogger`, outbox, inbox, reprocessamento |
| RF-13/15 franquias e visões | `FranchiseContext` (escopo validado no servidor a cada requisição) |
| RF-14 bloqueio financeiro global | `Services/Financial/FinancialStatusService` — sem liberação manual |

Critérios de homologação da seção 17 e 27.10: `tests/Feature/*` (`php artisan test`).

## Decisões pendentes (seção 19)

Ficam em **Gestão → Configurações e decisões** e desabilitam a funcionalidade enquanto pendentes:
recebimento presencial e reflexo na Vindi; franquia de origem no site; carência durante suspensão;
conta Vindi central x por franquia; desconto de planista no TudoPraPet. Planos, valores, coberturas,
carências e texto contratual reais devem ser cadastrados pela São Francisco — o seed de homologação
contém apenas exemplos sem validade comercial.

## Instalação na VPS (Ubuntu 24.04)

```bash
apt install nginx mysql-server redis-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd composer nodejs npm certbot
git clone git@github.com:User17055/PlanosSF.git /var/www/planossf && cd /var/www/planossf
cp .env.example .env            # preencher DB_*, APP_URL, VINDI_* (nunca versionar o .env)
composer install --no-dev --optimize-autoloader && npm ci && npm run build
php artisan key:generate && php artisan migrate --force && php artisan db:seed --force
php artisan planos:create-user admin@exemplo.com "Administrador" admin_tecnico CENTRAL
chown -R www-data:deploy storage bootstrap/cache && find storage bootstrap/cache -type d -exec chmod g+rwxs {} + && chmod 640 .env
```

- **Nginx:** raiz exclusivamente em `public/`; bloquear arquivos ocultos; HTTPS via certbot.
- **Fila:** `deploy/planossf-queue.service` → `/etc/systemd/system/` e `systemctl enable --now planossf-queue`.
- **Agendador:** `/etc/cron.d/planossf` com `* * * * * www-data cd /var/www/planossf && php artisan schedule:run`.
- **Webhook Vindi:** `https://<domínio>/webhooks/vindi/<VINDI_WEBHOOK_SECRET>`.
- **MySQL e Redis** escutando apenas em localhost.
- **Dois usuários de banco:** o `.env` usa o da aplicação (`planossf_app`), que não altera tabelas nem
  muda o livro de benefícios, a auditoria e as evidências de aceite (`deploy/app-grants.sh`). O dono do
  banco fica em `/root/.planossf-migrator.env` (`DB_USERNAME` e `DB_PASSWORD`, `chmod 600`), lido só
  pelo deploy para rodar as migrations.

## Implantação, rollback e backup

- Deploy: `bash deploy/deploy.sh` (faz dump do banco antes das migrations, reinicia workers e verifica).
- Rollback de código: `bash deploy/rollback.sh <commit>` — não desfaz banco; reversão de banco exige
  restaurar backup em homologação e nunca pode apagar pagamentos recebidos.
- Backup: `deploy/backup.sh` (banco + documentos criptografados, retenção 30 dias, cópia externa via
  `BACKUP_REMOTE`). Guarde a `APP_KEY` e a senha do backup fora da VPS. Teste a restauração.

## Rotinas agendadas

`planos:dispatch-integrations` (1 min) · `planos:reconcile --pending` (10 min) · `planos:expire-reservations`
(10 min) · `planos:activate-pending` / `planos:execute-cancellations` (15 min) · `planos:expire-proposals`,
`planos:review-due-dates` (1 h) · `planos:reconcile` completo (03:00). O painel mostra a última execução,
o tamanho e a idade da fila, falhas de PDF, webhooks e integrações pendentes.

## Endpoints internos (seção 14)

| Especificação | Rota |
|---|---|
| POST /proposals | `POST /painel/propostas` |
| POST /proposals/{id}/links | `POST /painel/propostas/{id}/link`, `/link/revogar` |
| POST /proposals/{id}/accept | `POST /p/{token}/aceite` (titular) |
| POST /contracts/{id}/files · /validate | `POST /painel/contratos/{id}/digitalizacao`, `/painel/arquivos/{id}/conferencia` |
| POST /subscriptions/{id}/checkout | `POST /painel/assinaturas/{id}/cobranca`, `POST /p/{token}/pagamento` |
| POST /webhooks/vindi | `POST /webhooks/vindi/{segredo}` |
| GET /subscriptions/{id}/eligibility | `GET /painel/autorizacao/assinatura/{id}` |
| POST /authorizations · consume · reverse | `POST /painel/autorizacao/assinatura/{id}/reservar`, `/painel/autorizacoes/{uuid}/executar`, `/estornar` |
| POST /subscriptions/{id}/cancel | `POST /painel/assinaturas/{id}/cancelar` |
| GET /customers/{id}/overview | `GET /painel/clientes/{id}` |

## Homologação

Com `PLANOS_BILLING_DRIVER=fake`, a página de pagamento é um simulador: aprova, recusa, estorna, emite a
próxima mensalidade ou torna a fatura vencida, e a confirmação chega por webhook como no provedor real.
Antes de produção: sandbox da Vindi validado, contrato aprovado, e-mail transacional configurado,
`PLANOS_HOMOLOGATION=false`, backups externos e restauração testada.
