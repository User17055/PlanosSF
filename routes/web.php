<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\FranchiseController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\FranchiseSwitchController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BenefitController;
use App\Http\Controllers\Catalog\BenefitRuleController;
use App\Http\Controllers\Catalog\ContractTemplateController;
use App\Http\Controllers\Catalog\PlanController;
use App\Http\Controllers\Catalog\PlanVersionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\PetController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\Public\HomologBillingController;
use App\Http\Controllers\Public\PublicProposalController;
use App\Http\Controllers\Public\SiteController;
use App\Http\Controllers\Public\WebhookController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TabletController;
use App\Http\Middleware\SetFranchiseContext;
use Illuminate\Support\Facades\Route;

// ---------------- Público: site institucional e link individual ----------------
Route::get('/', [SiteController::class, 'plans'])->name('site.plans');
Route::get('/planos/{version}', [SiteController::class, 'show'])->name('site.plan');
Route::post('/planos/{version}/contratar', [SiteController::class, 'start'])->middleware('throttle:public-forms')->name('site.start');

Route::prefix('p/{token}')->middleware('throttle:public-forms')->group(function () {
    Route::get('/', [PublicProposalController::class, 'show'])->name('public.proposal');
    Route::post('/dados', [PublicProposalController::class, 'saveForm'])->name('public.form');
    Route::post('/codigo', [PublicProposalController::class, 'sendCode'])->middleware('throttle:otp')->name('public.code.send');
    Route::post('/codigo/verificar', [PublicProposalController::class, 'verifyCode'])->name('public.code.verify');
    Route::post('/aceite', [PublicProposalController::class, 'accept'])->name('public.accept');
    Route::get('/contrato', [PublicProposalController::class, 'contract'])->name('public.contract');
    Route::post('/pagamento', [PublicProposalController::class, 'checkout'])->name('public.checkout');
    Route::get('/situacao', [PublicProposalController::class, 'status'])->name('public.status');
});

Route::post('/webhooks/vindi/{secret}', [WebhookController::class, 'vindi'])->middleware('throttle:webhooks')->name('webhooks.vindi');

// Simulador de cobrança (somente homologação; links assinados).
Route::prefix('homologacao/cobranca')->middleware('signed')->group(function () {
    Route::get('{bill}', [HomologBillingController::class, 'show'])->name('homolog.billing.show');
    Route::post('{bill}/pagar', [HomologBillingController::class, 'pay'])->name('homolog.billing.pay');
});

// ---------------- Portal do cliente ----------------
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('entrar', [PortalAuthController::class, 'show'])->name('login');
    Route::post('entrar', [PortalAuthController::class, 'send'])->middleware('throttle:otp')->name('login.send');
    Route::post('entrar/verificar', [PortalAuthController::class, 'verify'])->middleware('throttle:public-forms')->name('login.verify');
    Route::post('sair', [PortalAuthController::class, 'logout'])->name('logout');

    Route::middleware('portal')->group(function () {
        Route::get('/', [PortalController::class, 'home'])->name('home');
        Route::get('assinaturas/{subscription}', [PortalController::class, 'subscription'])->name('subscription');
        Route::get('assinaturas/{subscription}/contrato', [PortalController::class, 'contract'])->name('contract');
        Route::post('assinaturas/{subscription}/cancelamento', [PortalController::class, 'requestCancellation'])->name('cancel');
        Route::post('faturas/{invoice}/pagar', [PortalController::class, 'pay'])->name('pay');
        Route::post('dados', [PortalController::class, 'updateContact'])->name('contact');
    });
});

// ---------------- Área interna ----------------
Route::get('/entrar', [LoginController::class, 'show'])->middleware('guest')->name('login');
Route::post('/entrar', [LoginController::class, 'login'])->middleware(['guest', 'throttle:login']);
Route::post('/sair', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::prefix('painel')->middleware(['auth', SetFranchiseContext::class])->group(function () {
    Route::get('unidade', [FranchiseSwitchController::class, 'select'])->name('franchise.select');
    Route::post('unidade', [FranchiseSwitchController::class, 'switch'])->name('franchise.switch');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Clientes e pets
    Route::get('clientes', [CustomerController::class, 'index'])->middleware(['can:customers.view', 'throttle:document-lookup'])->name('customers.index');
    Route::get('clientes/novo', [CustomerController::class, 'create'])->middleware('can:customers.manage')->name('customers.create');
    Route::post('clientes', [CustomerController::class, 'store'])->middleware('can:customers.manage')->name('customers.store');
    Route::get('clientes/{customer}', [CustomerController::class, 'show'])->middleware('can:customers.view')->name('customers.show');
    Route::get('clientes/{customer}/editar', [CustomerController::class, 'edit'])->middleware('can:customers.manage')->name('customers.edit');
    Route::put('clientes/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.manage')->name('customers.update');
    Route::post('clientes/{customer}/anexos', [CustomerController::class, 'attach'])->middleware('can:documents.upload')->name('customers.attach');
    Route::get('anexos/{attachment}', [CustomerController::class, 'downloadAttachment'])->middleware('can:contracts.view')->name('attachments.download');
    Route::get('clientes/{customer}/pets/novo', [PetController::class, 'create'])->middleware('can:customers.manage')->name('pets.create');
    Route::post('clientes/{customer}/pets', [PetController::class, 'store'])->middleware('can:customers.manage')->name('pets.store');
    Route::get('pets/{pet}/editar', [PetController::class, 'edit'])->middleware('can:customers.manage')->name('pets.edit');
    Route::put('pets/{pet}', [PetController::class, 'update'])->middleware('can:customers.manage')->name('pets.update');

    // Catálogo
    Route::middleware('can:plans.view')->group(function () {
        Route::get('planos', [PlanController::class, 'index'])->name('plans.index');
        Route::get('planos/novo', [PlanController::class, 'create'])->middleware('can:plans.manage')->name('plans.create');
        Route::post('planos', [PlanController::class, 'store'])->middleware('can:plans.manage')->name('plans.store');
        Route::get('planos/{plan}', [PlanController::class, 'show'])->name('plans.show');
        Route::post('planos/{plan}/status', [PlanController::class, 'toggleStatus'])->middleware('can:plans.manage')->name('plans.status');
        Route::get('versoes/{version}', [PlanVersionController::class, 'show'])->name('versions.show');
        Route::put('versoes/{version}', [PlanVersionController::class, 'update'])->middleware('can:plans.manage')->name('versions.update');
        Route::post('versoes/{version}/nova', [PlanVersionController::class, 'draftFrom'])->middleware('can:plans.manage')->name('versions.draft');
        Route::post('versoes/{version}/publicar', [PlanVersionController::class, 'publish'])->middleware('can:plans.publish')->name('versions.publish');
        Route::post('versoes/{version}/despublicar', [PlanVersionController::class, 'unpublish'])->middleware('can:plans.publish')->name('versions.unpublish');
        Route::delete('versoes/{version}', [PlanVersionController::class, 'destroy'])->middleware('can:plans.manage')->name('versions.destroy');
        Route::post('versoes/{version}/beneficios', [BenefitRuleController::class, 'store'])->middleware('can:plans.manage')->name('rules.store');
        Route::put('beneficios/{rule}', [BenefitRuleController::class, 'update'])->middleware('can:plans.manage')->name('rules.update');
        Route::delete('beneficios/{rule}', [BenefitRuleController::class, 'destroy'])->middleware('can:plans.manage')->name('rules.destroy');
    });
    Route::middleware('can:templates.manage')->group(function () {
        Route::get('modelos', [ContractTemplateController::class, 'index'])->name('templates.index');
        Route::get('modelos/novo', [ContractTemplateController::class, 'create'])->name('templates.create');
        Route::post('modelos', [ContractTemplateController::class, 'store'])->name('templates.store');
        Route::get('modelos/{template}/editar', [ContractTemplateController::class, 'edit'])->name('templates.edit');
        Route::put('modelos/{template}', [ContractTemplateController::class, 'update'])->name('templates.update');
        Route::post('modelos/{template}/nova-versao', [ContractTemplateController::class, 'newVersion'])->name('templates.version');
    });

    // Contratação
    Route::get('propostas', [ProposalController::class, 'index'])->middleware('can:proposals.view')->name('proposals.index');
    Route::get('propostas/nova', [ProposalController::class, 'create'])->middleware('can:proposals.create')->name('proposals.create');
    Route::post('propostas', [ProposalController::class, 'store'])->middleware('can:proposals.create')->name('proposals.store');
    Route::get('propostas/{proposal}', [ProposalController::class, 'show'])->middleware('can:proposals.view')->name('proposals.show');
    Route::post('propostas/{proposal}/link', [ProposalController::class, 'issueLink'])->middleware('can:proposals.manage_links')->name('proposals.link');
    Route::post('propostas/{proposal}/link/revogar', [ProposalController::class, 'revokeLink'])->middleware('can:proposals.manage_links')->name('proposals.link.revoke');
    Route::post('propostas/{proposal}/link/enviado', [ProposalController::class, 'linkSent'])->middleware('can:proposals.manage_links')->name('proposals.link.sent');
    Route::post('propostas/{proposal}/revogar', [ProposalController::class, 'revoke'])->middleware('can:proposals.create')->name('proposals.revoke');
    Route::post('propostas/{proposal}/vincular-cliente', [ProposalController::class, 'resolveConflict'])->middleware('can:customers.merge')->name('proposals.resolve');
    Route::get('propostas/{proposal}/tablet', [TabletController::class, 'show'])->middleware('can:documents.upload')->name('tablet.show');
    Route::post('propostas/{proposal}/tablet', [TabletController::class, 'sign'])->middleware('can:documents.upload')->name('tablet.sign');
    Route::get('tablet/concluido/{contract}', [TabletController::class, 'done'])->middleware('can:documents.upload')->name('tablet.done');
    Route::post('propostas/{proposal}/papel', [DocumentController::class, 'preparePaper'])->middleware('can:documents.upload')->name('paper.prepare');

    // Documentos
    Route::get('documentos', [DocumentController::class, 'index'])->middleware('can:documents.validate')->name('documents.index');
    Route::post('contratos/{contract}/digitalizacao', [DocumentController::class, 'upload'])->middleware('can:documents.upload')->name('documents.upload');
    Route::post('arquivos/{file}/conferencia', [DocumentController::class, 'review'])->middleware('can:documents.validate')->name('documents.review');
    Route::post('contratos/{contract}/reprocessar', [DocumentController::class, 'regenerate'])->middleware('can:integrations.reprocess')->name('documents.regenerate');
    Route::get('arquivos/{file}', [DocumentController::class, 'download'])->middleware('can:contracts.view')->name('documents.download');

    // Assinaturas
    Route::get('assinaturas', [SubscriptionController::class, 'index'])->middleware('can:subscriptions.view')->name('subscriptions.index');
    Route::get('assinaturas/{subscription}', [SubscriptionController::class, 'show'])->middleware('can:subscriptions.view')->name('subscriptions.show');
    Route::post('assinaturas/{subscription}/cobranca', [SubscriptionController::class, 'checkout'])->middleware('can:proposals.create')->name('subscriptions.checkout');
    Route::post('assinaturas/{subscription}/cancelar', [SubscriptionController::class, 'cancel'])->middleware('can:subscriptions.cancel')->name('subscriptions.cancel');
    Route::post('cancelamentos/{cancellation}/aprovar', [SubscriptionController::class, 'approveCancellation'])->middleware('can:subscriptions.cancel')->name('cancellations.approve');

    // Autorização de benefícios
    Route::get('autorizacao', [BenefitController::class, 'index'])->middleware(['can:benefits.check', 'throttle:document-lookup'])->name('benefits.index');
    Route::get('autorizacao/assinatura/{subscription}', [BenefitController::class, 'subscription'])->middleware('can:benefits.check')->name('benefits.subscription');
    Route::post('autorizacao/assinatura/{subscription}/reservar', [BenefitController::class, 'reserve'])->middleware('can:benefits.reserve')->name('benefits.reserve');
    Route::get('autorizacoes/{authorization}', [BenefitController::class, 'show'])->middleware('can:benefits.check')->name('benefits.show');
    Route::post('autorizacoes/{authorization}/executar', [BenefitController::class, 'consume'])->middleware('can:benefits.consume')->name('benefits.consume');
    Route::post('autorizacoes/{authorization}/cancelar', [BenefitController::class, 'release'])->middleware('can:benefits.reserve')->name('benefits.release');
    Route::post('autorizacoes/{authorization}/estornar', [BenefitController::class, 'reverse'])->middleware('can:benefits.reverse')->name('benefits.reverse');

    // Financeiro
    Route::get('financeiro', [FinancialController::class, 'index'])->middleware('can:financial.view')->name('financial.index');
    Route::get('financeiro/faturas/{invoice}', [FinancialController::class, 'invoice'])->name('financial.invoice');
    Route::post('financeiro/faturas/{invoice}/recebimento', [FinancialController::class, 'registerPayment'])->name('financial.register');
    Route::post('financeiro/pagamentos/{payment}/validar', [FinancialController::class, 'validatePayment'])->middleware('can:financial.confirm_in_person')->name('financial.validate');
    Route::post('financeiro/faturas/{invoice}/ajuste', [FinancialController::class, 'requestAdjustment'])->middleware('can:financial.adjust')->name('financial.adjust');
    Route::post('financeiro/assinaturas/{subscription}/conciliar', [FinancialController::class, 'reconcile'])->middleware('can:financial.reconcile')->name('financial.reconcile');

    // Integrações
    Route::get('integracoes', [IntegrationController::class, 'index'])->middleware('can:integrations.view')->name('integrations.index');
    Route::post('integracoes/{job}/reprocessar', [IntegrationController::class, 'retry'])->middleware('can:integrations.reprocess')->name('integrations.retry');
    Route::post('integracoes/{job}/concluir', [IntegrationController::class, 'complete'])->middleware('can:integrations.complete_manual')->name('integrations.complete');
    Route::post('integracoes/webhooks/{inbox}/reprocessar', [IntegrationController::class, 'retryWebhook'])->middleware('can:integrations.reprocess')->name('integrations.webhook.retry');

    // Gestão
    Route::get('relatorios', [ReportController::class, 'index'])->middleware('can:reports.view')->name('reports.index');
    Route::get('relatorios/exportar/{report}', [ReportController::class, 'export'])->middleware('can:reports.view')->name('reports.export');
    Route::get('auditoria', [AuditController::class, 'index'])->middleware('can:audit.view')->name('audit.index');
    Route::middleware('can:settings.manage')->group(function () {
        Route::get('configuracoes', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('configuracoes', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('configuracoes/decisoes', [SettingsController::class, 'storeDecision'])->name('settings.decisions.store');
    });
    Route::middleware('can:users.manage')->group(function () {
        Route::get('usuarios', [UserController::class, 'index'])->name('users.index');
        Route::get('usuarios/novo', [UserController::class, 'create'])->name('users.create');
        Route::post('usuarios', [UserController::class, 'store'])->name('users.store');
        Route::get('usuarios/{user}/editar', [UserController::class, 'edit'])->name('users.edit');
        Route::put('usuarios/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('usuarios/{user}/vinculos', [UserController::class, 'addMembership'])->name('users.memberships.store');
        Route::delete('vinculos/{membership}', [UserController::class, 'removeMembership'])->name('users.memberships.destroy');
    });
    Route::middleware('can:franchises.manage')->group(function () {
        Route::get('franquias', [FranchiseController::class, 'index'])->name('franchises.index');
        Route::get('franquias/nova', [FranchiseController::class, 'create'])->name('franchises.create');
        Route::post('franquias', [FranchiseController::class, 'store'])->name('franchises.store');
        Route::get('franquias/{franchise}/editar', [FranchiseController::class, 'edit'])->name('franchises.edit');
        Route::put('franquias/{franchise}', [FranchiseController::class, 'update'])->name('franchises.update');
    });
});
