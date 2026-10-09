<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Billing\WebhookReceiver;
use Illuminate\Http\Request;

/**
 * POST /webhooks/vindi/{segredo} — entrada protegida; persiste antes de responder e processa em fila.
 */
class WebhookController extends Controller
{
    public function vindi(Request $request, string $secret, WebhookReceiver $receiver)
    {
        $expected = (string) config('planos.billing.vindi.webhook_secret');
        if ($expected === '' || ! hash_equals($expected, $secret)) {
            abort(404);
        }

        $payload = $request->json()->all();
        if (! is_array($payload) || ! isset($payload['event'])) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $receiver->receive('vindi', $payload);

        return response()->json(['status' => 'received'], 200);
    }
}
