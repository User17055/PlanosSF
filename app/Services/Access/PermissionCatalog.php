<?php

namespace App\Services\Access;

/**
 * Permissões específicas combinadas com papéis (seção 15 — Permissões administrativas).
 */
final class PermissionCatalog
{
    /** @return array<string, array{0: string, 1: string}> chave => [nome, grupo] */
    public static function permissions(): array
    {
        return [
            'customers.view' => ['Consultar tutores e pets', 'Clientes'],
            'customers.manage' => ['Cadastrar e editar tutores e pets', 'Clientes'],
            'customers.merge' => ['Mesclar cadastros duplicados', 'Clientes'],
            'plans.view' => ['Consultar catálogo de planos', 'Catálogo'],
            'plans.manage' => ['Editar rascunhos de planos e benefícios', 'Catálogo'],
            'plans.publish' => ['Publicar e despublicar versão de plano', 'Catálogo'],
            'templates.manage' => ['Gerenciar modelos de contrato', 'Catálogo'],
            'proposals.view' => ['Consultar propostas e links', 'Contratação'],
            'proposals.create' => ['Criar propostas', 'Contratação'],
            'proposals.manage_links' => ['Gerar, enviar e revogar links', 'Contratação'],
            'contracts.view' => ['Visualizar contrato', 'Documentos'],
            'documents.upload' => ['Anexar digitalização e conduzir assinatura presencial', 'Documentos'],
            'documents.validate' => ['Validar documento', 'Documentos'],
            'subscriptions.view' => ['Consultar assinaturas', 'Assinaturas'],
            'subscriptions.cancel' => ['Cancelar assinatura', 'Assinaturas'],
            'financial.view' => ['Consultar painel financeiro', 'Financeiro'],
            'financial.confirm_in_person' => ['Confirmar recebimento presencial', 'Financeiro'],
            'financial.adjust' => ['Solicitar ajustes financeiros', 'Financeiro'],
            'financial.reconcile' => ['Executar conciliação', 'Financeiro'],
            'benefits.check' => ['Consultar elegibilidade', 'Benefícios'],
            'benefits.reserve' => ['Reservar benefício', 'Benefícios'],
            'benefits.consume' => ['Confirmar execução de serviço', 'Benefícios'],
            'benefits.reverse' => ['Estornar utilização', 'Benefícios'],
            'benefits.approve_exception' => ['Aprovar exceção não financeira', 'Benefícios'],
            'integrations.view' => ['Consultar integrações', 'Integrações'],
            'integrations.reprocess' => ['Reprocessar integração', 'Integrações'],
            'integrations.complete_manual' => ['Concluir tarefa manual de integração', 'Integrações'],
            'reports.view' => ['Acessar relatórios', 'Gestão'],
            'audit.view' => ['Consultar auditoria', 'Gestão'],
            'settings.manage' => ['Configurações e registro de decisões', 'Gestão'],
            'users.manage' => ['Gerenciar usuários e vínculos', 'Gestão'],
            'franchises.manage' => ['Gerenciar franquias', 'Gestão'],
        ];
    }

    /** Permissões que só têm efeito no contexto da gestão central. */
    public static function centralOnly(): array
    {
        return ['plans.manage', 'plans.publish', 'templates.manage', 'franchises.manage', 'customers.merge', 'settings.manage'];
    }

    /** @return array<string, array{name: string, description: string, permissions: array<int, string>}> */
    public static function roles(): array
    {
        return [
            'vendedor' => [
                'name' => 'Vendedor',
                'description' => 'Cadastra propostas e acompanha conversão; não confirma pagamento.',
                'permissions' => [
                    'customers.view', 'customers.manage', 'plans.view', 'proposals.view', 'proposals.create',
                    'proposals.manage_links', 'contracts.view', 'documents.upload', 'subscriptions.view', 'benefits.check',
                ],
            ],
            'recepcao' => [
                'name' => 'Recepção',
                'description' => 'Consulta elegibilidade, reserva e registra serviços autorizados.',
                'permissions' => [
                    'customers.view', 'customers.manage', 'plans.view', 'proposals.view', 'contracts.view',
                    'documents.upload', 'subscriptions.view', 'benefits.check', 'benefits.reserve', 'benefits.consume',
                    'integrations.view', 'integrations.complete_manual',
                ],
            ],
            'financeiro' => [
                'name' => 'Financeiro',
                'description' => 'Concilia recebimentos, analisa divergências e confirma recebimento presencial.',
                'permissions' => [
                    'customers.view', 'plans.view', 'proposals.view', 'contracts.view', 'subscriptions.view',
                    'financial.view', 'financial.confirm_in_person', 'financial.adjust', 'financial.reconcile',
                    'benefits.check', 'reports.view',
                ],
            ],
            'gestor' => [
                'name' => 'Gestor',
                'description' => 'Aprova exceções, configura regras e acessa relatórios.',
                'permissions' => [
                    'customers.view', 'customers.manage', 'customers.merge', 'plans.view', 'plans.manage', 'plans.publish',
                    'templates.manage', 'proposals.view', 'proposals.create', 'proposals.manage_links', 'contracts.view',
                    'documents.upload', 'documents.validate', 'subscriptions.view', 'subscriptions.cancel',
                    'financial.view', 'benefits.check', 'benefits.reserve', 'benefits.consume', 'benefits.reverse',
                    'benefits.approve_exception', 'integrations.view', 'integrations.complete_manual',
                    'reports.view', 'audit.view', 'users.manage',
                ],
            ],
            'admin_tecnico' => [
                'name' => 'Administrador técnico',
                'description' => 'Acessos e integrações, sem direito automático de alterar contratos ou receber pagamentos.',
                'permissions' => [
                    'integrations.view', 'integrations.reprocess', 'settings.manage', 'audit.view',
                    'users.manage', 'franchises.manage',
                ],
            ],
        ];
    }
}
