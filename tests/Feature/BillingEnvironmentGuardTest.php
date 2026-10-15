<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\FakeBillingGateway;
use App\Services\Billing\VindiBillingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsScenario;
use Tests\TestCase;

/**
 * Trava homologação × produção: fora de homologação, nada de simulador de cobrança nem sandbox.
 */
class BillingEnvironmentGuardTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    protected function pendingInvoice(): Invoice
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);
        $this->acceptDigital($proposal);

        return app(CheckoutService::class)->start($proposal->fresh()->subscription);
    }

    public function test_em_homologacao_o_simulador_abre_e_aprova_pagamento(): void
    {
        $invoice = $this->pendingInvoice();

        $this->get($invoice->payment_url)->assertOk();
        $this->assertInstanceOf(FakeBillingGateway::class, app(BillingGateway::class));
    }

    public function test_fora_de_homologacao_link_do_simulador_da_404_e_nao_paga(): void
    {
        $invoice = $this->pendingInvoice();
        $payUrl = URL::signedRoute('homolog.billing.pay', ['bill' => $invoice->external_id]);

        config(['planos.is_homologation' => false]);

        $this->get($invoice->payment_url)->assertNotFound();
        $this->post($payUrl, ['action' => 'pay'])->assertNotFound();
        $this->assertSame(InvoiceStatus::Pending, $invoice->fresh()->status);
    }

    public function test_fora_de_homologacao_driver_fake_e_recusado(): void
    {
        config(['planos.is_homologation' => false, 'planos.billing.driver' => 'fake']);

        $this->expectExceptionMessage('PLANOS_BILLING_DRIVER=fake');
        app(BillingGateway::class);
    }

    public function test_fora_de_homologacao_vindi_nao_aponta_para_o_sandbox(): void
    {
        config([
            'planos.is_homologation' => false,
            'planos.billing.driver' => 'vindi',
            'planos.billing.vindi.base_url' => 'https://sandbox-app.vindi.com.br/api/v1',
        ]);

        $this->expectExceptionMessage('sandbox');
        app(BillingGateway::class);
    }

    public function test_fora_de_homologacao_vindi_de_producao_e_aceita(): void
    {
        config([
            'planos.is_homologation' => false,
            'planos.billing.driver' => 'vindi',
            'planos.billing.vindi.base_url' => 'https://app.vindi.com.br/api/v1',
        ]);

        $this->assertInstanceOf(VindiBillingGateway::class, app(BillingGateway::class));
    }

    public function test_driver_desconhecido_e_recusado_em_vez_de_cair_no_simulador(): void
    {
        config(['planos.billing.driver' => 'Vindi']);

        $this->expectExceptionMessage('desconhecido');
        app(BillingGateway::class);
    }
}
