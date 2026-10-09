<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Access\FranchiseContext;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! $user->is_active || ! Auth::attempt(['email' => strtolower($data['email']), 'password' => $data['password'], 'is_active' => true])) {
            // Mensagem genérica: não revela se o e-mail está cadastrado.
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->log('auth.login', $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request, AuditLogger $audit)
    {
        $audit->log('auth.logout', $request->user());
        Auth::guard('web')->logout();
        $request->session()->forget(FranchiseContext::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
