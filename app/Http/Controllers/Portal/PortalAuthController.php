<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePortalCustomer;
use App\Models\ContactVerification;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Services\Proposals\ContactVerificationService;
use App\Support\Cpf;
use Illuminate\Http\Request;

/**
 * Acesso por código de uso único enviado ao e-mail cadastrado. A resposta é sempre a mesma,
 * para não revelar quem está cadastrado (seção 16).
 */
class PortalAuthController extends Controller
{
    public function show()
    {
        return view('portal.login');
    }

    public function send(Request $request, ContactVerificationService $otp)
    {
        $request->validate(['document' => 'required|string|max:20']);
        $doc = Cpf::normalize($request->input('document'));
        $customer = $doc ? Customer::where('document_normalized', $doc)->whereNotNull('email')->first() : null;

        if ($customer) {
            $v = $otp->send('portal_login', $customer->email, null, $customer);
            $request->session()->put('portal_otp', $v->id);
        } else {
            $request->session()->put('portal_otp', 0);
        }

        return back()->with('code_sent', true)->with('success', 'Se houver cadastro com este CPF, enviamos um código para o e-mail registrado.');
    }

    public function verify(Request $request, ContactVerificationService $otp, AuditLogger $audit)
    {
        $request->validate(['code' => 'required|string|max:10']);
        $v = ContactVerification::where('purpose', 'portal_login')->find((int) $request->session()->get('portal_otp'));
        if (! $v) {
            return back()->with('code_sent', true)->withErrors(['code' => 'Código inválido ou expirado. Solicite um novo código.']);
        }
        $otp->verify($v, $request->input('code'));

        $request->session()->regenerate();
        $request->session()->forget('portal_otp');
        $request->session()->put(EnsurePortalCustomer::SESSION_KEY, $v->customer_id);
        $request->session()->put(EnsurePortalCustomer::SESSION_EXPIRES, now()->addHours(2)->timestamp);
        Customer::whereKey($v->customer_id)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
        $audit->log('portal.login', Customer::find($v->customer_id), null, null, 'customer');

        return redirect()->route('portal.home');
    }

    public function logout(Request $request)
    {
        $request->session()->forget([EnsurePortalCustomer::SESSION_KEY, EnsurePortalCustomer::SESSION_EXPIRES]);
        $request->session()->regenerate();

        return redirect()->route('portal.login');
    }
}
