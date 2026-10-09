<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal do cliente: acesso somente aos próprios dados e pets após verificação por código.
 */
class EnsurePortalCustomer
{
    public const SESSION_KEY = 'portal_customer_id';

    public const SESSION_EXPIRES = 'portal_expires_at';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get(self::SESSION_KEY);
        $expires = $request->session()->get(self::SESSION_EXPIRES);

        if (! $id || ! $expires || now()->timestamp > $expires || ! ($customer = Customer::find($id))) {
            $request->session()->forget([self::SESSION_KEY, self::SESSION_EXPIRES]);

            return redirect()->route('portal.login');
        }

        $request->attributes->set('portal_customer', $customer);

        return $next($request);
    }
}
