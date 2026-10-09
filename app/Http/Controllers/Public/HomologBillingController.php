<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\FakeBillingGateway;
use App\Services\Billing\WebhookReceiver;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Página de pagamento simulada (homologação). A confirmação chega ao sistema por webhook,
 * exatamente como no provedor real; voltar para o site não confirma pagamento.
 */
class HomologBillingController extends Controller
{
    public function __construct(protected BillingGateway $gateway)
    {
        abort_unless($gateway instanceof FakeBillingGateway, 404);
    }

    public function show(string $bill)
    {
        return view('public.homolog-billing', [
            'bill' => $this->gateway->getBill($bill),
            'payUrl' => URL::signedRoute('homolog.billing.pay', ['bill' => $bill]),
        ]);
    }

    public function pay(Request $request, string $bill, WebhookReceiver $receiver)
    {
        /** @var FakeBillingGateway $fake */
        $fake = $this->gateway;
        $action = $request->input('action', 'pay');
        $data = $fake->getBill($bill);
        $type = 'bill_paid';

        switch ($action) {
            case 'pay':
                $data = $fake->pay($bill);
                break;
            case 'reject':
                return back()->with('warning', 'Pagamento recusado pelo simulador. A fatura continua pendente.');
            case 'overdue':
                $data = $fake->updateBill($bill, ['due_date' => BusinessTime::today()->subDays(2)->format('Y-m-d')]);
                $type = 'bill_created';
                break;
            case 'refund':
                $data = $fake->refund($bill);
                $type = 'charge_refunded';
                break;
            case 'next':
                $data = $fake->createNextBill($data->subscriptionExternalId);
                $type = 'bill_created';
                break;
        }

        $receiver->receive('fake', ['event' => [
            'id' => 'fake-'.$type.'-'.$data->externalId.'-'.now()->getTimestampMs(),
            'type' => $type,
            'created_at' => now()->toIso8601String(),
            'data' => ['bill' => ['id' => $data->externalId, 'subscription' => ['id' => $data->subscriptionExternalId]]],
        ]]);

        return back()->with('success', match ($action) {
            'pay' => 'Pagamento aprovado no simulador. A confirmação foi enviada ao sistema por webhook.',
            'overdue' => 'Vencimento da fatura movido para o passado (teste de inadimplência).',
            'refund' => 'Estorno simulado e notificado.',
            'next' => 'Próxima mensalidade emitida: '.$data->externalId,
            default => 'Ok',
        });
    }
}
